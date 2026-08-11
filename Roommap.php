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
                // $tokens — в порядке появления слов в названии (для показа).
                $tokens = $this->roomGrouperNormalize($roomName, $extraStop);
                if (count($tokens) === 0) {
                    // Подстраховка: нормализация всегда возвращает хотя бы низшую
                    // категорию, но на всякий случай не оставляем пустой набор.
                    $tokens = array($this->roomGrouperDefaultGrade());
                }

                // Отсортированная копия — только для ключа (чтобы "Sea View
                // Superior" и "Superior Sea View" попадали в одну группу).
                $sorted = $tokens;
                usort($sorted, array($this, 'roomGrouperCompareTokens'));

                $key = implode('-', $sorted);
                $signature = $this->roomGrouperSignature($sorted);
                $simTokens = $this->roomGrouperSimilarityTokens($sorted);

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
     * Нормализация названия номера в набор канонических токенов
     * В ПОРЯДКЕ ИХ ПОЯВЛЕНИЯ в исходном названии (для показа названия группы).
     * Сортировка для ключа группы выполняется в groupHotelRooms().
     *
     * @param string $name исходное название номера
     * @param array $extraStop дополнительные стоп-слова (слово => true)
     * @return array канонические токены в порядке появления
     */
    public function roomGrouperNormalize($name, array $extraStop = array())
    {
        $s = $this->roomGrouperLower($name);

        // HTML-сущности из выгрузок поставщиков ("&amp;" -> "&"), иначе
        // после чистки пунктуации остаётся мусорный токен "amp".
        $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
        // Символы площади ² / ³ -> цифры, чтобы "m²" стало "m2".
        $s = str_replace(array('²', '³'), array('2', '3'), $s);
        // Похожие на латинскую "i" символы (кириллица/греческий/полноширинные)
        // -> "i", чтобы опечатки в "suite" распознавались (правка 50).
        $s = $this->roomGrouperNormalizeConfusables($s);

        // "..." и всё справа от них — отбрасываем (правка 62).
        $dots = strpos($s, '...');
        if ($dots !== false) {
            $s = substr($s, 0, $dots) . ' ';
        }
        // "Gift"/"Complimentary" и всё справа — отбрасываем (правка 59).
        $cut = preg_replace('/\b(?:gift|complimentary)\b.*$/s', ' ', $s);
        if ($cut !== null) {
            $s = $cut;
        }

        // Скобки с "Bedroom#1:" (конфигурация комнат) — вырезаем (правка 52).
        $s = preg_replace('/\([^()]*bedroom\s*#[^()]*\)/u', ' ', $s);

        // Любые скобки, содержащие цифру (конфигурация кроватей, размещение:
        // "(1 King bed + 2 Other beds)", "(Up To 3+2)", "(2AD+1CH)") —
        // вырезаем целиком; кроме скобок про количество спален (bedroom).
        $s = preg_replace('/\((?![^()]*bedroom)[^()]*\d[^()]*\)/u', ' ', $s);

        // Скобки, содержащие ТОЛЬКО конфигурацию кроватей, вырезаются целиком:
        // "(KING OR TWIN)", "(King bed + Other beds)".
        // Скобки с содержательными словами ("(POOL VIEW)", "(DELUXE)") остаются.
        $s = $this->roomGrouperStripBedConfig($s);

        // Пунктуацию и разделители — в пробелы
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        if ($clean === null) { // на случай отсутствия PCRE-UTF8
            $clean = preg_replace('/[^a-z0-9]+/i', ' ', $s);
        }
        $s = ' ' . trim($clean) . ' ';

        // Площадь номера ("25 sqm", "25sqm", "300 sq ft", "25 m2") — убираем.
        $noArea = preg_replace('/(?<= )\d+ ?(?:sq ?m|sq ?ft|sqmt|sqmts|sqm|sqft|m2|ft2)(?= )/', ' ', $s);
        if ($noArea !== null) {
            $s = $noArea;
            $s = ' ' . trim(preg_replace('/\s+/', ' ', $s)) . ' ';
        }

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

        // "<слово> or <слово>" -> оба слова неопределённы, убираем оба
        // ("balcony or terrace", "king or twin") — правка 58.
        $eitherOr = preg_replace('/(?<= )[a-z0-9]+ or [a-z0-9]+(?= )/', ' ', $s);
        if ($eitherOr !== null) {
            $s = $eitherOr;
        }

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
            // Одиночные буквы (например "s" из "Bed(s)") не несут смысла
            if (strlen($token) === 1) {
                continue;
            }
            // Числа сами по себе (например "2" из "capacity 2") не несут категории
            if (preg_match('/^\d+$/', $token)) {
                continue;
            }
            // "2ad", "1ch", "3pax", "1inf" и т.п. — размещение, игнорируем
            // (в т.ч. остатки от "(2AD+1CH)": 2ad и 1ch по отдельности)
            if (preg_match('/^\d+(?:ad|adt|adl|adult|adults|ch|chd|child|children|kid|kids|inf|infant|infants|cnb|pax|px|person|persons|guest|guests|ppl)$/', $token)) {
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

        // Bare-локация без слова "view" не делит категорию по виду:
        // "Standard Garden"/"Standard Land" -> просто Standard (правка 44).
        // "Garden View"/"Land View" уже стали gardenview/landview выше.
        foreach ($this->roomGrouperBareLocationDrop() as $loc => $ignored) {
            unset($tokens[$loc]);
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

        // Тип кровати не участвует в группировке: номера с опциями
        // "King or Twin", "Double/Twin", "1 King Or 2 Twin" не должны
        // дробиться по кроватям и подписываться одним типом.
        foreach ($this->roomGrouperBeddingTokens() as $bedToken => $ignored) {
            unset($tokens[$bedToken]);
        }

        // Восстанавливаем ведущее "exclusive" как признак категории —
        // ставим в начало (грейд ведёт название).
        if ($leadingExclusive) {
            $tokens = array('exclusive' => true) + $tokens;
        }

        // Если в названии нет класса/уровня номера — bare "Double"/"Triple",
        // "Double with Balcony", просто "Room", один вид или рекламный текст —
        // относим к самой низшей категории (Standard), сохраняя вид/признаки.
        if (!$this->roomGrouperHasGrade($tokens)) {
            $tokens = array($this->roomGrouperDefaultGrade() => true) + $tokens;
        }

        // Порядок токенов = порядок появления в названии (правка 30).
        // Сортировка для ключа/сигнатуры делается в groupHotelRooms().
        return array_keys($tokens);
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
                if (!preg_match('/^(?:\d+|one|two|three|single|double|twin|queen|king|sofa|large|kids|extra|bunk|bed|beds|and|or|size|other|murphy|pull|trundle|rollaway|futon|day)$/u', $word)) {
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

    /**
     * Заменяет похожие на латинскую "i" символы (кириллица, греческий,
     * полноширинные, dotless i) на обычную "i" — чтобы опечатки в словах
     * вроде "suіte" (с не-латинской i) распознавались корректно (правка 50).
     */
    public function roomGrouperNormalizeConfusables($s)
    {
        $map = array(
            "\xD1\x96" => 'i', // U+0456 cyrillic small i
            "\xD0\x86" => 'i', // U+0406 cyrillic capital I
            "\xCE\xB9" => 'i', // U+03B9 greek small iota
            "\xCE\x99" => 'i', // U+0399 greek capital iota
            "\xC4\xB1" => 'i', // U+0131 latin small dotless i
            "\xC9\xA9" => 'i', // U+0269 latin small iota
            "\xEF\xBD\x89" => 'i', // U+FF49 fullwidth latin small i
            "\xEF\xBC\xA9" => 'i', // U+FF29 fullwidth latin capital I
        );
        return str_replace(array_keys($map), array_values($map), $s);
    }

    /**
     * Bare-локации (без слова "view"), которые не должны делить категорию:
     * "Standard Garden"/"Standard Land" -> просто Standard (правка 44).
     */
    public function roomGrouperBareLocationDrop()
    {
        return array('garden' => true, 'land' => true, 'park' => true);
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
            // Индивидуальный бассейн в номере/на вилле (в названии — "Pool")
            'private pool'    => 'privatepool',
            'plunge pool'     => 'privatepool',
            'own pool'        => 'privatepool',
            // Pool + слово о виде -> Pool View (а НЕ индивидуальный бассейн)
            'view of the pool' => 'poolview',
            'view of pool'    => 'poolview',
            'overlooking the pool' => 'poolview',
            'overlooking pool' => 'poolview',
            'pool side'       => 'poolview',
            'pool facing'     => 'poolview',
            'facing pool'     => 'poolview',
            'pool front'      => 'poolview',
            // "pool or sea view" должна сработать раньше "sea view"/"pool view"
            'pool or sea view' => 'poolview seaview',
            'sea or pool view' => 'seaview poolview',
            // Указан выбор из двух видов -> без определённого вида (правка 45)
            'sea view or garden view' => '',
            'garden view or sea view' => '',
            'sea view or pool view'   => '',
            'pool view or sea view'   => '',
            'sea or garden view'      => '',
            'garden or sea view'      => '',
            // Джакузи: разные названия -> одна опция (правка 31)
            'hot tub'         => 'jacuzzi',
            'hot tube'        => 'jacuzzi',
            'jetted tub'      => 'jacuzzi',
            // Semi Double = Twin (правка 33)
            'semi double'     => 'twin',
            // Основной корпус отеля — сохраняем в названии (правка 35)
            'main building'   => 'mainbuilding',
            'main buildings'  => 'mainbuilding',
            'main bulding'    => 'mainbuilding',
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
            // Те же формы, но где "seaview" написано слитно (см. правку 29)
            'partial seaview'    => 'partialseaview',
            'partial oceanview'  => 'partialseaview',
            'side seaview'       => 'partialseaview',
            'lateral seaview'    => 'partialseaview',
            'lateralsea view'    => 'partialseaview',
            'limited seaview'    => 'partialseaview',
            'obstructed seaview' => 'partialseaview',
            'seaview limited'    => 'partialseaview',
            // "upon request" / "on request" -> убрать (как subject to availability)
            'upon request'    => '',
            'on request'      => '',
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
            'panoramic view'  => 'panoramicview', // Panoramic == Panoramic View (пр.70)
            'junior suite'    => 'juniorsuite',
            'garden view'     => 'gardenview',
            'ocean view'      => 'seaview',
            'sea front'       => 'seaview', // Seafront = Sea View (правка 53)
            // Категория номера определяется при заезде (Run of House)
            'room assigned on arrival' => 'roh',
            'assigned on arrival' => 'roh',
            'assigned upon arrival' => 'roh',
            'run of the house' => 'roh',
            'run of house'    => 'roh',
            // No Window(s) -> одна группа "No Window" (правка 66)
            'no window'       => 'nowindow',
            'no windows'      => 'nowindow',
            // Non-Smoking убираем из названия; "smoking" (для курящих) остаётся (пр.60)
            'non smoking'     => '',
            'no smoking'      => '',
            // Примечания-заметки, не влияющие на категорию номера
            'travel agent flexible rate' => '', // (правки 54, 57)
            'flexible rate'   => '',
            'buffet breakfast' => '',
            'room only'       => '',
            'bed only'        => '',
            'sitting area'    => '', // (правка 55)
            'seating area'    => '',
            'living area'     => '',
            'vip perks'       => '', // (правка 59)
            'newly refurbished' => '', // (правка 63)
            'newly renovated' => '',
            'spa access'      => '', // (правка 64)
            'no amendments permitted' => '', // (правка 65)
            'no amendments'   => '',
            'mini fridge'     => '', // (правка 68)
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
            'dwb' => 'double', 'twb' => 'twin', // DWB=Double Bed, TWB=Twin Bed
            'semidouble' => 'twin', // Semi Double = Twin (правка 33)
            'trpl' => 'triple', 'tpl' => 'triple',
            'qdpl' => 'quad', 'quadruple' => 'quad',
            'fam' => 'family',
            'dorm' => 'dormitory', 'dorms' => 'dormitory',

            // Класс номера
            'std' => 'standard',
            'standart' => 'standard', // частые опечатки
            'stadard' => 'standard',
            'standarts' => 'standard', 'standards' => 'standard',
            'sup' => 'superior',
            'dlx' => 'deluxe', 'delux' => 'deluxe',
            'suit' => 'suite', 'sute' => 'suite', 'suits' => 'suite', // опечатки
            'improved' => 'superior',
            'exec' => 'executive',
            'econom' => 'economy',
            'eco' => 'economy', // Eco Room = Economy (правка 39)
            'economic' => 'economy', // Economic = Economy (правка 67)
            'apt' => 'apartment', 'apts' => 'apartment',

            // Виды (аббревиатуры)
            'sv' => 'seaview',
            'gv' => 'gardenview',
            'sea' => 'seaview', // одиночное "sea" = вид на море
            'seafront' => 'seaview', // Seafront = Sea View (правка 53)
            'panoramic' => 'panoramicview', // Panoramic == Panoramic View (пр.70)
            'seaside' => 'partialseaview', // sea side -> ограниченный вид на море
            'sideseaview' => 'partialseaview',   // SIDESEAVIEW (правка 41)
            'lateralseaview' => 'partialseaview', // (правка 40)
            'seasideview' => 'partialseaview',
            'poolside' => 'poolview', // вид/сторона бассейна -> Pool View (правка 42)
            // Одиночный "pool" / "with pool" -> просто "Pool" (правки 46, 56);
            // "private/plunge pool" -> отдельный "Private Pool" (см. фразы выше)
            'windowless' => 'nowindow',

            // Пристройка/корпус: опечатки и британское написание -> annex
            'anex' => 'annex', 'annexe' => 'annex', 'annexes' => 'annex',
            // Смежные (соединённые) номера -> общий токен (правка 36)
            'connection' => 'connecting', 'connected' => 'connecting',
            'interconnecting' => 'connecting', 'interconnected' => 'connecting',
            'interconnection' => 'connecting',

            // Джакузи (правка 31)
            'hottub' => 'jacuzzi', 'jacuzi' => 'jacuzzi',
            'jaccuzzi' => 'jacuzzi', 'whirlpool' => 'jacuzzi',

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
            // Размещение (2ad/1ch/3pax обрабатываются отдельно в нормализации)
            'ad', 'adt', 'adl', 'ch', 'chd', 'inf', 'infant', 'infants', 'cnb',
            'pax', 'ppl', 'person', 'persons', 'guest', 'guests',
            // Площадь номера (числовые формы убираются в нормализации)
            'sqm', 'sqft', 'sqmt', 'sqmts', 'm2', 'ft2', 'sq', 'mts',
            // Питание / тарифные пометки — не влияют на категорию номера
            'breakfast', 'dinner', 'lunch', 'meal', 'meals', 'board',
            'inclusive', 'included', 'ultra', 'allinclusive',
            'ai', 'uai', 'bb', 'hb', 'fb',
            'refundable', 'nonrefundable', 'nonref', 'refund', 'rate', 'rates',
            // Типы тарифов и питаний (правки 54, 57): "Flexible Rate",
            // "Buffet Breakfast", "(BB NR)", "(BB BAR FLEX)", "Travel Agent"
            'travel', 'agent', 'flexible', 'flex', 'buffet', 'bar', 'nr',
            'ro', 'net', 'rack', 'corporate', 'corp',
            // Комментарии: "(bed type is subject to availability)",
            // "(extra bed not included)", "upon request", "bed not specified"
            'is', 'are', 'subject', 'availability', 'available', 'type', 'types',
            'not', 'no', 'excluded', 'on', 'upon', 'request', 'amp',
            'specified', 'unspecified', 'specify',
            // Заполняемость / зоны / оборудование (правки 51, 55, 68)
            'max', 'maximum', 'min', 'minimum',
            'use', 'usage', 'sole', 'murphy', 'trundle',
            'sitting', 'seating', 'area', 'living',
            'fridge', 'refrigerator', 'minibar',
            // Парковка (правка 61)
            'parking', 'valet',
            // Ремонт (правка 63)
            'newly', 'refurbished', 'renovated', 'renovation', 'refurbishment', 'refurb',
            // Спа-доступ / изменения брони (правки 64, 65)
            'spa', 'access', 'amendments', 'amendment', 'permitted',
            // Non-Smoking убираем; "smoking" (для курящих) остаётся (правка 60)
            'nonsmoking',
            // Рекламный / маркетинговый текст — не влияет на категорию номера
            'offer', 'offers', 'deal', 'deals', 'discount', 'discounted',
            'promo', 'promotion', 'promotional', 'save', 'savings', 'saver',
            'sale', 'special', 'specials', 'bonus', 'exclusive', 'perks', 'off',
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
            'exclusive' => 'grade', 'luxury' => 'grade', 'spectacular' => 'grade',
            'premier' => 'grade', 'elite' => 'grade', 'pavilion' => 'grade',
            'dormitory' => 'grade', 'diamond' => 'grade', 'comfort' => 'grade',
            // Вид из окна (partialseaview — ограниченный вид на море,
            // отдельный от полного seaview)
            'seaview' => 'view', 'partialseaview' => 'view',
            'gardenview' => 'view', 'cityview' => 'view',
            'poolview' => 'view', 'mountainview' => 'view',
            'panoramicview' => 'view',
            // Вместимость (single/double/twin/queen/king — типы кроватей,
            // исключаются из группировки; triple/quad — реальная вместимость)
            'triple' => 'capacity', 'quad' => 'capacity',
            // Бассейн: swim-up (общий бассейн), pool access (доступ), "Pool"
            // (with pool) и "Private Pool" — разные категории, не сливаются
            'swimup' => 'access', 'poolaccess' => 'access',
            'pool' => 'access', 'privatepool' => 'access',
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
            'luxury' => 10, 'spectacular' => 10, 'premier' => 10,
            'elite' => 10, 'pavilion' => 10,
            'dormitory' => 10, 'diamond' => 10, 'comfort' => 10,
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
            'gardenview' => 40, 'cityview' => 40, 'panoramicview' => 40,
            'poolview' => 40, 'mountainview' => 40, 'swimup' => 45,
            'poolaccess' => 45, 'pool' => 45, 'privatepool' => 45,
            // Атрибуты / расположение
            'jacuzzi' => 50, 'balcony' => 50, 'terrace' => 50,
            'connecting' => 54, 'mainbuilding' => 55, 'annex' => 55,
            'nowindow' => 58, 'smoking' => 60,
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
            'panoramicview' => 'Panoramic View',
            'juniorsuite'  => 'Junior Suite',
            'swimup'       => 'Swim-Up',
            'poolaccess'   => 'Pool Access',
            'pool'         => 'Pool', // "With Pool"/"Pool" -> "Pool" (правки 46, 56)
            'privatepool'  => 'Private Pool', // "Private/Plunge Pool" (правка 56)
            'mainbuilding' => 'Main Building',
            'nowindow'     => 'No Window',
            'roh'          => 'Run of House',
        );
    }

}
