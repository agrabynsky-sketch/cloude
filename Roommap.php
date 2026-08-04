<?php
/**
 * Группировка названий отельных номеров от разных поставщиков
 * в уникальные семантические категории "на лету", без заранее
 * подготовленного маппинга между поставщиками.
 *
 * Идея алгоритма:
 *  1. Название нормализуется: нижний регистр, вырезаются скобки, содержащие
 *     только конфигурацию кроватей ("(2 TWIN BEDS OR 1 QUEEN BED)"),
 *     пунктуация заменяется пробелами, многословные обороты склеиваются
 *     ("sea view" -> seaview, "swim up"/"pool access" -> swimup; при этом
 *     "with pool"/"pool villa" — индивидуальный бассейн -> privatepool).
 *  2. Каждый токен приводится к каноническому виду по словарю синонимов
 *     и аббревиатур (dbl -> double, sv -> seaview, "двухместный" -> double...),
 *     стоп-слова (room, wifi, free, sofa, "номер"...) отбрасываются.
 *  3. Тип кровати (single/double/twin/queen/king) полностью исключается из
 *     группировки: номера с опциями "King or Twin", "Double/Twin Room" не
 *     дробятся и не подписываются одним типом кровати. Номер, названный
 *     ТОЛЬКО по кровати ("Double", "Twin"), относится к низшей категории
 *     (Standard). Остальные токены делятся на смысловые классы:
 *       - критические (класс номера, вид из окна, вместимость, доступ
 *         к бассейну) — при объединении групп должны совпадать точно;
 *       - остальные — участвуют в мере сходства.
 *  4. Токены сортируются по смысловому весу и образуют детерминированный
 *     ключ группы. Если точного ключа нет, ищется группа с совпадающими
 *     критическими классами и достаточным коэффициентом Жаккара по
 *     остальным токенам.
 *  5. Название категории собирается из ключевых слов группы.
 *
 * Код совместим с PHP 5.2+ (без короткого синтаксиса массивов и замыканий),
 * работает и на PHP 7/8.
 */
class Hub_Hotel_Action_Content_Roommap extends Hub_Hotel_Abstract {

    /**
     * Главный метод: группирует названия номеров в семантические категории.
     *
     * @param array $supplierRooms массив вида:
     *        array(
     *            'SupplierA' => array('Standard DBL Room', 'Suite Sea View', ...),
     *            'SupplierB' => array('STD Double', ...),
     *        )
     * @param float $similarityThreshold порог сходства Жаккара (0..1) для
     *        объединения неполностью совпадающих наборов токенов
     * @param array $noiseWords дополнительные шумовые слова, специфичные для
     *        ваших поставщиков (например, название отеля:
     *        array('jaz', 'bluemarine')) — они будут отброшены при нормализации
     *
     * @return array массив категорий:
     *        array(
     *            'deluxe-family-poolview' => array(
     *                'category' => 'Deluxe Family Pool View',
     *                'tokens'   => array('deluxe', 'family', 'poolview'),
     *                'rooms'    => array(
     *                    array('supplier' => 'SupplierA', 'name' => 'FAMILY DELUXE POOL VIEW'),
     *                    array('supplier' => 'SupplierB', 'name' => 'Deluxe Family Room (Pool View)'),
     *                ),
     *            ),
     *            ...
     *        )
     */
    public function groupHotelRooms(array $supplierRooms, $similarityThreshold = 0.6, array $noiseWords = array())
    {
        $groups = array();

        $extraStop = array();
        foreach ($noiseWords as $word) {
            $extraStop[$this->roomGrouperLower($word)] = true;
        }

        foreach ($supplierRooms as $supplier => $roomNames) {
            if (!is_array($roomNames)) {
                continue;
            }
            foreach ($roomNames as $roomName) {
                $tokens = $this->roomGrouperNormalize($roomName, $extraStop);
                if (count($tokens) === 0) {
                    // Подстраховка: нормализация всегда возвращает хотя бы низшую
                    // категорию, но на всякий случай не оставляем пустой набор.
                    $tokens = array($this->roomGrouperDefaultGrade());
                }

                $key = implode('-', $tokens);
                $signature = $this->roomGrouperSignature($tokens);
                $simTokens = $this->roomGrouperSimilarityTokens($tokens);

                // 1. Точное совпадение ключа
                if (!isset($groups[$key])) {
                    // 2. Поиск похожей группы: критические классы должны совпадать
                    //    точно, остальные токены сравниваются по Жаккару
                    $bestKey = null;
                    $bestScore = 0.0;
                    foreach ($groups as $existingKey => $group) {
                        if ($group['signature'] !== $signature) {
                            continue; // конфликт по классу/виду/вместимости — не сливаем
                        }
                        $score = $this->roomGrouperJaccard($simTokens, $group['sim_tokens']);
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $bestKey = $existingKey;
                        }
                    }
                    if ($bestKey !== null && $bestScore >= $similarityThreshold) {
                        $key = $bestKey;
                    }
                }

                if (!isset($groups[$key])) {
                    $groups[$key] = array(
                        'category'   => $this->roomGrouperBuildCategoryName($tokens),
                        'tokens'     => $tokens,
                        'signature'  => $signature,
                        'sim_tokens' => $simTokens,
                        'rooms'      => array(),
                    );
                }

                $groups[$key]['rooms'][] = array(
                    'supplier' => $supplier,
                    'name'     => $roomName,
                );
            }
        }

        return $groups;
    }

    /**
     * Нормализация названия номера в отсортированный набор канонических токенов.
     *
     * @param string $name исходное название номера
     * @param array $extraStop дополнительные стоп-слова (слово => true)
     * @return array канонические токены, отсортированные по смысловому весу
     */
    public function roomGrouperNormalize($name, array $extraStop = array())
    {
        $s = $this->roomGrouperLower($name);

        // Скобки, содержащие ТОЛЬКО конфигурацию кроватей, вырезаются целиком:
        // "(1 QUEEN BED)", "(2 TWIN BEDS OR 1 QUEEN BED)".
        // Скобки с содержательными словами ("(POOL VIEW)", "(DELUXE)") остаются.
        $s = $this->roomGrouperStripBedConfig($s);

        // Пунктуацию и разделители — в пробелы
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        if ($clean === null) { // на случай отсутствия PCRE-UTF8
            $clean = preg_replace('/[^a-z0-9]+/i', ' ', $s);
        }
        $s = ' ' . trim($clean) . ' ';

        // Многословные обороты -> один токен (до разбиения на слова).
        // Более длинные фразы применяются первыми, чтобы "pool or sea view"
        // сработала раньше, чем "sea view" или "pool view".
        $phrases = $this->roomGrouperPhraseMap();
        uksort($phrases, array($this, 'roomGrouperComparePhraseKeys'));
        foreach ($phrases as $phrase => $canonical) {
            $s = str_replace(' ' . $phrase . ' ', ' ' . $canonical . ' ', $s);
        }

        // "exclusive" в НАЧАЛЕ названия — значимый признак категории, его
        // сохраняем; в середине это обычно рекламное слово (см. стоп-слова).
        $leadingExclusive = (strpos($s, ' exclusive ') === 0);

        // Любой "<слово> view" (кроме уже распознанных видов) склеиваем в
        // единый токен-вид: "Eiffel View" -> eiffelview, "land view" ==
        // "landview". Так значимые/лендмарк-виды сохраняются и не дробятся.
        $s = $this->roomGrouperCollapseViews($s);

        // "N bedroom(s)" / "one/two... bedroom(s)" -> токен Nbedroom, чтобы
        // количество спален не терялось ("2 Bedrooms") и не дописывалось.
        $s = $this->roomGrouperCollapseBedrooms($s);

        $rawTokens = preg_split('/\s+/u', trim($s));
        if ($rawTokens === false) {
            $rawTokens = explode(' ', trim($s));
        }

        $synonyms  = $this->roomGrouperSynonymMap();
        $stopWords = $this->roomGrouperStopWords();

        $tokens = array();
        foreach ($rawTokens as $token) {
            if ($token === '' || isset($stopWords[$token]) || isset($extraStop[$token])) {
                continue;
            }
            // Числа сами по себе (например "2" из "capacity 2") не несут категории
            if (preg_match('/^\d+$/', $token)) {
                continue;
            }
            // "2ad", "3pax" и т.п. — обозначение размещения (2 adults), игнорируем
            if (preg_match('/^\d+(?:ad|adt|adl|adult|adults|pax|px|person|persons|guest|guests|ppl)$/', $token)) {
                continue;
            }
            if (isset($synonyms[$token])) {
                $token = $synonyms[$token];
            }
            if ($token === '' || isset($stopWords[$token]) || isset($extraStop[$token])) {
                continue;
            }
            $tokens[$token] = true; // уникальность
        }

        // Комбинации токенов, образующие одно понятие независимо от порядка слов
        // ("Junior Suite" и "Suite Junior" -> juniorsuite)
        foreach ($this->roomGrouperTokenCombos() as $combo) {
            $parts = $combo[0];
            $canonical = $combo[1];
            $allPresent = true;
            foreach ($parts as $part) {
                if (!isset($tokens[$part])) {
                    $allPresent = false;
                    break;
                }
            }
            if ($allPresent) {
                foreach ($parts as $part) {
                    unset($tokens[$part]);
                }
                $tokens[$canonical] = true;
            }
        }

        // Восстанавливаем ведущее "exclusive" как признак категории.
        if ($leadingExclusive) {
            $tokens['exclusive'] = true;
        }

        // Тип кровати не участвует в группировке: номера с опциями
        // "King or Twin", "Double/Twin", "1 King Or 2 Twin" не должны
        // дробиться по кроватям и подписываться одним типом.
        foreach ($this->roomGrouperBeddingTokens() as $bedToken => $ignored) {
            unset($tokens[$bedToken]);
        }

        // Если в названии нет класса/уровня номера — bare "Double"/"Triple",
        // "Double with Balcony", просто "Room", один вид или рекламный текст —
        // относим к самой низшей категории (Standard), сохраняя вид/признаки.
        if (!$this->roomGrouperHasGrade($tokens)) {
            $tokens[$this->roomGrouperDefaultGrade()] = true;
        }

        $tokens = array_keys($tokens);
        usort($tokens, array($this, 'roomGrouperCompareTokens'));

        return $tokens;
    }

    /**
     * Вырезает скобки, содержащие только конфигурацию кроватей.
     *
     * @param string $s
     * @return string
     */
    public function roomGrouperStripBedConfig($s)
    {
        if (!preg_match_all('/\(([^()]*)\)/u', $s, $matches, PREG_SET_ORDER)) {
            return $s;
        }
        foreach ($matches as $match) {
            $inner = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $match[1]);
            if ($inner === null) {
                $inner = preg_replace('/[^a-z0-9]+/i', ' ', $match[1]);
            }
            $words = preg_split('/\s+/u', trim($inner));
            if ($words === false || count($words) === 0) {
                continue;
            }
            $onlyBedConfig = true;
            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }
                if (!preg_match('/^(?:\d+|one|two|three|single|double|twin|queen|king|sofa|large|kids|extra|bunk|bed|beds|and|or|size)$/u', $word)) {
                    $onlyBedConfig = false;
                    break;
                }
            }
            if ($onlyBedConfig) {
                $s = str_replace($match[0], ' ', $s);
            }
        }
        return $s;
    }

    /**
     * Склеивает "<слово> view" в единый токен-вид "<слово>view" для видов,
     * не распознанных фразами (лендмарки: eiffel view -> eiffelview) и для
     * форм без пробела ("land view" == "landview"). Известные структурные
     * слова (класс, вместимость, кровать) при этом не склеиваются.
     *
     * @param string $s строка в нижнем регистре, окружённая пробелами
     * @return string
     */
    public function roomGrouperCollapseViews($s)
    {
        $result = preg_replace_callback(
            '/(?<= )([a-z0-9]+) view(?= )/',
            array($this, 'roomGrouperViewGlueCallback'),
            $s
        );
        return ($result === null) ? $s : $result;
    }

    /** Колбэк склейки: возвращает "<слово>view" или исходное совпадение. */
    public function roomGrouperViewGlueCallback($m)
    {
        $word = $m[1];
        if ($this->roomGrouperIsStructuralWord($word)) {
            return $m[0]; // "Superior View", "Family View" — не склеиваем
        }
        return $word . 'view';
    }

    /** Слово относится к известным (класс/вид/вместимость/кровать/стоп/число). */
    public function roomGrouperIsStructuralWord($word)
    {
        if (preg_match('/^\d+$/', $word)) {
            return true;
        }
        $stop = $this->roomGrouperStopWords();
        if (isset($stop[$word])) {
            return true;
        }
        $syn = $this->roomGrouperSynonymMap();
        if (isset($syn[$word])) {
            return true;
        }
        $classes = $this->roomGrouperTokenClasses();
        if (isset($classes[$word])) {
            return true;
        }
        $bedding = $this->roomGrouperBeddingTokens();
        if (isset($bedding[$word])) {
            return true;
        }
        return false;
    }

    /**
     * "N bedroom(s)" и словесные формы ("one/two/three bedroom(s)")
     * приводит к единому токену "Nbedroom" (2bedroom, 3bedroom...).
     *
     * @param string $s строка в нижнем регистре, окружённая пробелами
     * @return string
     */
    public function roomGrouperCollapseBedrooms($s)
    {
        $result = preg_replace('/(?<= )(\d+) ?bedrooms?(?= )/', '$1bedroom', $s);
        if ($result !== null) {
            $s = $result;
        }
        $words = array(
            'one' => '1', 'two' => '2', 'three' => '3',
            'four' => '4', 'five' => '5', 'six' => '6',
        );
        foreach ($words as $word => $digit) {
            $s = str_replace(' ' . $word . ' bedroom ',  ' ' . $digit . 'bedroom ', $s);
            $s = str_replace(' ' . $word . ' bedrooms ', ' ' . $digit . 'bedroom ', $s);
        }
        return $s;
    }

    /** Есть ли в наборе токен класса "grade" (класс/уровень номера). */
    public function roomGrouperHasGrade(array $tokens)
    {
        $classes = $this->roomGrouperTokenClasses();
        foreach ($tokens as $token => $ignored) {
            if (isset($classes[$token]) && $classes[$token] === 'grade') {
                return true;
            }
        }
        return false;
    }

    /**
     * Токены, важные для сходства. Типы кроватей к этому моменту уже удалены
     * из набора в roomGrouperNormalize(), так что здесь возвращаются все токены.
     * Метод оставлен как точка расширения для будущих "мягких" токенов.
     *
     * @param array $tokens
     * @return array
     */
    public function roomGrouperSimilarityTokens(array $tokens)
    {
        $bedding = $this->roomGrouperBeddingTokens();
        $result = array();
        foreach ($tokens as $token) {
            if (!isset($bedding[$token])) {
                $result[] = $token;
            }
        }
        return $result;
    }

    /**
     * Сигнатура критических классов: для каждого класса (grade/view/capacity/access)
     * — отсортированный список токенов. Две группы могут объединяться только
     * при полном совпадении сигнатур: sea view не сольётся с pool view,
     * deluxe — со standard, triple — с double и т.д.
     *
     * @param array $tokens
     * @return array
     */
    public function roomGrouperSignature(array $tokens)
    {
        $classes = $this->roomGrouperTokenClasses();
        $signature = array(
            'grade' => array(), 'view' => array(), 'capacity' => array(),
            'access' => array(), 'bedrooms' => array(),
        );
        foreach ($tokens as $token) {
            if (isset($classes[$token])) {
                $signature[$classes[$token]][] = $token;
            } elseif (preg_match('/^\d+bedroom$/', $token)) {
                // Количество спален — критический класс: "1 Bedroom Mountain
                // View" и "Mountain View" не должны сливаться (см. правку 15)
                $signature['bedrooms'][] = $token;
            } elseif (substr($token, -4) === 'view') {
                // Нераспознанный вид (лендмарк: eiffelview, landview...) —
                // тоже критический класс "view", чтобы не сливаться с прочим
                $signature['view'][] = $token;
            }
        }
        foreach ($signature as $class => $classTokens) {
            sort($classTokens);
            $signature[$class] = implode(',', $classTokens);
        }
        return $signature;
    }

    /**
     * Коэффициент Жаккара для двух наборов токенов: |A ∩ B| / |A ∪ B|.
     * Два пустых набора считаются идентичными (1.0).
     *
     * @param array $a
     * @param array $b
     * @return float
     */
    public function roomGrouperJaccard(array $a, array $b)
    {
        if (count($a) === 0 && count($b) === 0) {
            return 1.0;
        }
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));
        if ($union === 0) {
            return 0.0;
        }
        return $intersection / $union;
    }

    /**
     * Человекочитаемое название категории из ключевых токенов.
     * Порядок: класс номера, вместимость, кровати, вид, атрибуты.
     *
     * @param array $tokens
     * @return string
     */
    public function roomGrouperBuildCategoryName(array $tokens)
    {
        $labels = $this->roomGrouperDisplayLabels();
        $parts = array();
        foreach ($tokens as $token) {
            if (isset($labels[$token])) {
                $parts[] = $labels[$token];
            } elseif (preg_match('/^(\d+)bedroom$/', $token, $mm)) {
                // 1bedroom -> "1 Bedroom", 2bedroom -> "2 Bedroom" ...
                $parts[] = $mm[1] . ' Bedroom';
            } elseif (strlen($token) > 4 && substr($token, -4) === 'view') {
                // Нераспознанный вид: eiffelview -> "Eiffel View", landview -> "Land View"
                $parts[] = ucfirst(substr($token, 0, -4)) . ' View';
            } else {
                $parts[] = ucfirst($token);
            }
        }
        return implode(' ', $parts);
    }

    /**
     * Сортировка токенов по смысловому весу (меньше — важнее),
     * при равном весе — по алфавиту, чтобы ключ был детерминированным.
     *
     * @param string $a
     * @param string $b
     * @return int
     */
    public function roomGrouperCompareTokens($a, $b)
    {
        $wa = $this->roomGrouperTokenWeight($a);
        $wb = $this->roomGrouperTokenWeight($b);
        if ($wa === $wb) {
            return strcmp($a, $b);
        }
        return ($wa < $wb) ? -1 : 1;
    }

    /** Вес токена, включая динамические ("Nbedroom", лендмарк-виды). */
    public function roomGrouperTokenWeight($token)
    {
        $weights = $this->roomGrouperTokenWeights();
        if (isset($weights[$token])) {
            return $weights[$token];
        }
        if (preg_match('/^\d+bedroom$/', $token)) {
            return 30;
        }
        if (substr($token, -4) === 'view') {
            return 42; // лендмарк/прочие виды — рядом со стандартными видами
        }
        return 100;
    }

    /**
     * Сортировка фраз: более длинные применяются первыми.
     *
     * @param string $a
     * @param string $b
     * @return int
     */
    public function roomGrouperComparePhraseKeys($a, $b)
    {
        return strlen($b) - strlen($a);
    }

    /** Регистронезависимое приведение с поддержкой UTF-8. */
    public function roomGrouperLower($s)
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($s, 'UTF-8');
        }
        return strtolower($s);
    }

    /* ------------------------------------------------------------------ *
     *  Словари. Их можно расширять по мере появления новых поставщиков —
     *  сам алгоритм при этом не меняется.
     * ------------------------------------------------------------------ */

    /**
     * Многословные обороты -> канонический токен (применяются до разбиения).
     */
    public function roomGrouperPhraseMap()
    {
        return array(
            // Swim-up: номер с прямым выходом в общий бассейн вдоль номеров
            'swim up'         => 'swimup',
            // Pool access: доступ к бассейну — отдельная категория, НЕ swim-up
            'access to outdoor pool' => 'poolaccess',
            'access to pool'  => 'poolaccess',
            'pool access'     => 'poolaccess',
            // Индивидуальный бассейн в номере/на вилле
            'private pool'    => 'privatepool',
            'plunge pool'     => 'privatepool',
            'own pool'        => 'privatepool',
            // "pool or sea view" должна сработать раньше "sea view"/"pool view"
            'pool or sea view' => 'poolview seaview',
            'sea or pool view' => 'seaview poolview',
            // Ограниченный вид на море — отдельная категория, НЕ полный Sea View
            'partial sea view'   => 'partialseaview',
            'partial ocean view' => 'partialseaview',
            'side sea view'      => 'partialseaview',
            'side ocean view'    => 'partialseaview',
            'lateral sea view'   => 'partialseaview',
            'lateral ocean view' => 'partialseaview',
            'sea view limited'   => 'partialseaview',
            'limited sea view'   => 'partialseaview',
            'obstructed sea view' => 'partialseaview',
            'sea view partial'   => 'partialseaview',
            'sea side'           => 'partialseaview',
            // Питание и тарифные пометки не влияют на категорию номера
            'ultra all inclusive' => '',
            'all inclusive ultra' => '',
            'bed and breakfast' => '',
            'all inclusive'   => '',
            'breakfast included' => '',
            'dinner included' => '',
            'lunch included'  => '',
            'non refundable'  => '',
            'non ref'         => '',
            'mountain view'   => 'mountainview',
            'junior suite'    => 'juniorsuite',
            'garden view'     => 'gardenview',
            'ocean view'      => 'seaview',
            // Категория номера определяется при заезде (Run of House)
            'room assigned on arrival' => 'roh',
            'assigned on arrival' => 'roh',
            'assigned upon arrival' => 'roh',
            'run of the house' => 'roh',
            'run of house'    => 'roh',
            // Кол-во спален ("N bedroom(s)") обрабатывается в
            // roomGrouperCollapseBedrooms(), отдельные фразы тут не нужны.
            'non smoking'     => 'nonsmoking',
            'pool view'       => 'poolview',
            'city view'       => 'cityview',
            'sea view'        => 'seaview',
            'de luxe'         => 'deluxe',
            'king size'       => 'king',
            'queen size'      => 'queen',
            'half board'      => '', // питание не влияет на категорию номера
            'full board'      => '',
            'free wifi'       => '',
        );
    }

    /**
     * Наборы токенов, которые вместе образуют одно понятие (порядок слов не важен).
     * Формат: array(array(составные_токены), 'канонический_токен').
     */
    public function roomGrouperTokenCombos()
    {
        return array(
            array(array('junior', 'suite'), 'juniorsuite'),
        );
    }

    /** Одиночные токены: синонимы и аббревиатуры -> канонический токен. */
    public function roomGrouperSynonymMap()
    {
        return array(
            // Вместимость / тип размещения
            'sgl' => 'single', 'sngl' => 'single',
            'dbl' => 'double', 'dble' => 'double',
            'casal' => 'double', // португальское "двуспальная кровать"
            'twn' => 'twin',
            'trpl' => 'triple', 'tpl' => 'triple',
            'qdpl' => 'quad', 'quadruple' => 'quad',
            'fam' => 'family',

            // Класс номера
            'std' => 'standard',
            'standart' => 'standard', // частая опечатка (T на конце)
            'standarts' => 'standard', 'standards' => 'standard',
            'sup' => 'superior',
            'dlx' => 'deluxe',
            'suit' => 'suite',
            'improved' => 'superior',
            'exec' => 'executive',
            'econom' => 'economy',
            'apt' => 'apartment', 'apts' => 'apartment',

            // Виды (аббревиатуры)
            'sv' => 'seaview',
            'gv' => 'gardenview',
            'seaside' => 'partialseaview', // sea side -> ограниченный вид на море
            // Одиночный "pool" вне фраз ("Pool Villa", "Villa with Pool") означает
            // индивидуальный бассейн; вид на бассейн всегда пишется как "pool view"
            'pool' => 'privatepool',

            // Пристройка/корпус: опечатки и британское написание -> annex
            'anex' => 'annex', 'annexe' => 'annex', 'annexes' => 'annex',

            // Атрибуты
            'balc' => 'balcony',

            // Единственное/множественное число
            'suites' => 'suite',
            'apartments' => 'apartment',
            'villas' => 'villa',
            'studios' => 'studio',
            'duplexes' => 'duplex',
        );
    }

    /** Слова, не несущие категорийного смысла, — отбрасываются. */
    public function roomGrouperStopWords()
    {
        return array_flip(array(
            'room', 'rooms',
            'with', 'and', 'or', 'the', 'a', 'an', 'in', 'of', 'for', 'to',
            'one', 'two', 'three', 'four', 'five', 'six',
            'bed', 'beds',
            'adults', 'adult',
            'kids', 'kid', 'child', 'children',
            'sofa', 'large', 'extra', 'bunk', 'size',
            'free', 'wifi', 'internet',
            'capacity', 'view', 'side', 'outdoor',
            // Квалификаторы вида. Формы "partial/side/limited sea view"
            // распознаются раньше как отдельный вид partialseaview (см.
            // roomGrouperPhraseMap); здесь эти слова отбрасываются лишь как
            // остаточный шум в прочих контекстах. "full/unobstructed sea
            // view" — это обычный полный Sea View, квалификатор не нужен.
            'partial', 'limited', 'obstructed', 'inland', 'lateral',
            'full', 'unobstructed',
            'only', 'new', 'main', 'building',
            // Размещение (2ad/3pax обрабатываются отдельно в нормализации)
            'ad', 'adt', 'adl', 'pax', 'ppl', 'person', 'persons', 'guest', 'guests',
            // Питание / тарифные пометки — не влияют на категорию номера
            'breakfast', 'dinner', 'lunch', 'meal', 'meals', 'board',
            'inclusive', 'included', 'ultra', 'allinclusive',
            'ai', 'uai', 'bb', 'hb', 'fb',
            'refundable', 'nonrefundable', 'nonref', 'refund', 'rate',
            // Комментарии вида "(bed type is subject to availability)"
            'is', 'are', 'subject', 'availability', 'available', 'type', 'types',
            // Рекламный / маркетинговый текст — не влияет на категорию номера
            'offer', 'offers', 'deal', 'deals', 'discount', 'discounted',
            'promo', 'promotion', 'promotional', 'save', 'savings', 'saver',
            'sale', 'special', 'specials', 'bonus', 'exclusive', 'off',
            'early', 'bird', 'earlybird', 'last', 'minute', 'lastminute',
            'book', 'booking', 'getaway', 'escape', 'package', 'stay',
            'summer', 'winter', 'spring', 'autumn', 'fall',
            'season', 'seasonal', 'holiday', 'holidays', 'festive',
            'christmas', 'xmas', 'easter', 'newyear',
        ));
    }

    /**
     * Критические классы токенов. Внутри одного класса значения должны
     * совпадать точно, чтобы группы можно было объединить.
     */
    public function roomGrouperTokenClasses()
    {
        return array(
            // Класс/уровень номера
            'economy' => 'grade', 'standard' => 'grade', 'superior' => 'grade',
            'deluxe' => 'grade', 'premium' => 'grade', 'suite' => 'grade',
            'juniorsuite' => 'grade', 'presidential' => 'grade',
            'executive' => 'grade', 'apartment' => 'grade', 'studio' => 'grade',
            'bungalow' => 'grade', 'villa' => 'grade', 'cottage' => 'grade',
            'family' => 'grade', 'duplex' => 'grade', 'roh' => 'grade',
            'exclusive' => 'grade',
            // Вид из окна (partialseaview — ограниченный вид на море,
            // отдельный от полного seaview)
            'seaview' => 'view', 'partialseaview' => 'view',
            'gardenview' => 'view', 'cityview' => 'view',
            'poolview' => 'view', 'mountainview' => 'view',
            // Вместимость (single/double/twin/queen/king — типы кроватей,
            // исключаются из группировки; triple/quad — реальная вместимость)
            'triple' => 'capacity', 'quad' => 'capacity',
            // Бассейн: swim-up (выход в общий бассейн), pool access (доступ
            // к бассейну) и индивидуальный бассейн — три разные категории,
            // точное сравнение класса не даст им слиться
            'swimup' => 'access', 'poolaccess' => 'access', 'privatepool' => 'access',
        );
    }

    /**
     * Типы кроватей: полностью удаляются из набора токенов при нормализации,
     * поэтому не влияют ни на ключ группы, ни на её название, ни на сравнение.
     */
    public function roomGrouperBeddingTokens()
    {
        return array(
            'single' => true, 'double' => true, 'twin' => true,
            'queen' => true, 'king' => true,
        );
    }

    /**
     * Самая низшая категория номера. Присваивается номерам, названным
     * только по типу кровати ("Double", "Twin", "Double/Twin Room").
     *
     * @return string
     */
    public function roomGrouperDefaultGrade()
    {
        return 'standard';
    }

    /**
     * Смысловой вес токена для порядка в ключе и названии категории.
     * Меньше — важнее (идёт первым).
     */
    public function roomGrouperTokenWeights()
    {
        return array(
            // Класс номера
            'economy' => 10, 'standard' => 10, 'superior' => 10, 'deluxe' => 10,
            'premium' => 10, 'suite' => 10, 'juniorsuite' => 10,
            'presidential' => 10, 'executive' => 11, 'exclusive' => 11,
            'apartment' => 10, 'studio' => 10, 'bungalow' => 10,
            'villa' => 10, 'cottage' => 10,
            'family' => 12, 'duplex' => 12, 'roh' => 10,
            // Вместимость
            'single' => 20, 'double' => 20, 'twin' => 20, 'triple' => 20,
            'quad' => 20,
            // Кровати / спальни
            'king' => 30, 'queen' => 30, '1bedroom' => 30, '2bedroom' => 30,
            // Виды и доступ к бассейну
            'seaview' => 40, 'partialseaview' => 41,
            'gardenview' => 40, 'cityview' => 40,
            'poolview' => 40, 'mountainview' => 40, 'swimup' => 45,
            'poolaccess' => 45, 'privatepool' => 45,
            // Атрибуты
            'balcony' => 50, 'terrace' => 50, 'nonsmoking' => 60,
        );
    }

    /** Красивые подписи для канонических токенов в названии категории. */
    public function roomGrouperDisplayLabels()
    {
        return array(
            'seaview'      => 'Sea View',
            'partialseaview' => 'Partial Sea View',
            'gardenview'   => 'Garden View',
            'cityview'     => 'City View',
            'poolview'     => 'Pool View',
            'mountainview' => 'Mountain View',
            'juniorsuite'  => 'Junior Suite',
            'nonsmoking'   => 'Non-Smoking',
            'swimup'       => 'Swim-Up',
            'poolaccess'   => 'Pool Access',
            'privatepool'  => 'Private Pool',
            'roh'          => 'Run of House',
        );
    }

}
