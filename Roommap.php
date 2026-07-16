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
 *  3. Токены делятся на смысловые классы:
 *       - критические (класс номера, вид из окна, вместимость, доступ
 *         к бассейну) — при объединении групп должны совпадать точно;
 *       - "кроватные" (double, twin, queen, king) — сохраняются в названии
 *         категории, но НЕ участвуют в сравнении;
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
class Hub_Hotel_Action_Content_Roommap extends Hub_Hotel_Abstract
{
    /** @var float порог сходства Жаккара (0..1) для объединения групп */
    protected $_similarityThreshold = 0.6;

    /** @var array дополнительные шумовые слова (слово => true) */
    protected $_noiseWords = array();

    /**
     * Порог сходства для объединения неполностью совпадающих наборов токенов.
     *
     * @param float $threshold значение 0..1
     * @return Hub_Hotel_Action_Content_Roommap
     */
    public function setSimilarityThreshold($threshold)
    {
        $this->_similarityThreshold = (float) $threshold;
        return $this;
    }

    /**
     * Дополнительные шумовые слова, специфичные для поставщиков
     * (например, название отеля: array('jaz', 'bluemarine')) —
     * они будут отброшены при нормализации.
     *
     * @param array $words
     * @return Hub_Hotel_Action_Content_Roommap
     */
    public function setNoiseWords(array $words)
    {
        $this->_noiseWords = array();
        foreach ($words as $word) {
            $this->_noiseWords[$this->_lower($word)] = true;
        }
        return $this;
    }

    /**
     * Главный метод: группирует названия номеров в семантические категории.
     *
     * @param array $supplierRooms массив вида:
     *        array(
     *            'ИмяПоставщика1' => array('Standard DBL Room', 'Suite Sea View', ...),
     *            'ИмяПоставщика2' => array('Двухместный стандарт', ...),
     *        )
     *
     * @return array массив категорий:
     *        array(
     *            'deluxe-family-poolview' => array(
     *                'category' => 'Deluxe Family Pool View', // сгенерированное название
     *                'tokens'   => array('deluxe', 'family', 'poolview'),
     *                'rooms'    => array(
     *                    array('supplier' => 'ИмяПоставщика1', 'name' => 'FAMILY DELUXE POOL VIEW'),
     *                    array('supplier' => 'ИмяПоставщика2', 'name' => 'Deluxe Family Room (Pool View)'),
     *                ),
     *            ),
     *            ...
     *        )
     */
    public function group(array $supplierRooms)
    {
        $groups = array();

        foreach ($supplierRooms as $supplier => $roomNames) {
            if (!is_array($roomNames)) {
                continue;
            }
            foreach ($roomNames as $roomName) {
                $tokens = $this->_normalize($roomName);
                if (count($tokens) === 0) {
                    // Ничего осмысленного не извлекли — отдельная категория "как есть"
                    $tokens = array($this->_lower(trim($roomName)));
                }

                $key = implode('-', $tokens);
                $signature = $this->_signature($tokens);
                $simTokens = $this->_similarityTokens($tokens);

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
                        $score = $this->_jaccard($simTokens, $group['sim_tokens']);
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $bestKey = $existingKey;
                        }
                    }
                    if ($bestKey !== null && $bestScore >= $this->_similarityThreshold) {
                        $key = $bestKey;
                    }
                }

                if (!isset($groups[$key])) {
                    $groups[$key] = array(
                        'category'   => $this->_buildCategoryName($tokens),
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
     * @return array канонические токены, отсортированные по смысловому весу
     */
    protected function _normalize($name)
    {
        $s = $this->_lower($name);

        // Скобки, содержащие ТОЛЬКО конфигурацию кроватей, вырезаются целиком:
        // "(1 QUEEN BED)", "(2 TWIN BEDS OR 1 QUEEN BED)".
        // Скобки с содержательными словами ("(POOL VIEW)", "(DELUXE)") остаются.
        $s = $this->_stripBedConfig($s);

        // Пунктуацию и разделители — в пробелы
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        if ($clean === null) { // на случай отсутствия PCRE-UTF8
            $clean = preg_replace('/[^a-z0-9а-яё]+/i', ' ', $s);
        }
        $s = ' ' . trim($clean) . ' ';

        // Многословные обороты -> один токен (до разбиения на слова).
        // Более длинные фразы применяются первыми, чтобы "pool or sea view"
        // сработала раньше, чем "sea view" или "pool view".
        $phrases = $this->_phraseMap();
        uksort($phrases, array($this, '_comparePhraseKeys'));
        foreach ($phrases as $phrase => $canonical) {
            $s = str_replace(' ' . $phrase . ' ', ' ' . $canonical . ' ', $s);
        }

        $rawTokens = preg_split('/\s+/u', trim($s));
        if ($rawTokens === false) {
            $rawTokens = explode(' ', trim($s));
        }

        $synonyms  = $this->_synonymMap();
        $stopWords = $this->_stopWords();

        $tokens = array();
        foreach ($rawTokens as $token) {
            if ($token === '' || isset($stopWords[$token]) || isset($this->_noiseWords[$token])) {
                continue;
            }
            // Числа сами по себе (например "2" из "capacity 2") не несут категории
            if (preg_match('/^\d+$/', $token)) {
                continue;
            }
            if (isset($synonyms[$token])) {
                $token = $synonyms[$token];
            }
            if ($token === '' || isset($stopWords[$token]) || isset($this->_noiseWords[$token])) {
                continue;
            }
            $tokens[$token] = true; // уникальность
        }

        // Комбинации токенов, образующие одно понятие независимо от порядка слов
        // ("Junior Suite" и "Suite Junior" -> juniorsuite)
        foreach ($this->_tokenCombos() as $combo) {
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

        $tokens = array_keys($tokens);
        usort($tokens, array($this, '_compareTokens'));

        return $tokens;
    }

    /**
     * Вырезает скобки, содержащие только конфигурацию кроватей.
     *
     * @param string $s
     * @return string
     */
    protected function _stripBedConfig($s)
    {
        if (!preg_match_all('/\(([^()]*)\)/u', $s, $matches, PREG_SET_ORDER)) {
            return $s;
        }
        foreach ($matches as $match) {
            $inner = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $match[1]);
            if ($inner === null) {
                $inner = preg_replace('/[^a-z0-9а-яё]+/i', ' ', $match[1]);
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
                if (!preg_match('/^(?:\d+|one|two|three|single|double|twin|queen|king|sofa|large|kids|extra|bunk|bed|beds|and|or|size|кровать|кровати|кроватей|односпальная|двуспальная)$/u', $word)) {
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
     * Токены, важные для сходства: всё, кроме "кроватных" (double/twin/queen/king).
     * Кроватные токены остаются в ключе и названии категории, но не влияют
     * на сравнение групп: "Superior Sea View" == "Superior Twin Sea View".
     *
     * @param array $tokens
     * @return array
     */
    protected function _similarityTokens(array $tokens)
    {
        $bedding = $this->_beddingTokens();
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
    protected function _signature(array $tokens)
    {
        $classes = $this->_tokenClasses();
        $signature = array('grade' => array(), 'view' => array(), 'capacity' => array(), 'access' => array());
        foreach ($tokens as $token) {
            if (isset($classes[$token])) {
                $signature[$classes[$token]][] = $token;
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
    protected function _jaccard(array $a, array $b)
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
    protected function _buildCategoryName(array $tokens)
    {
        $labels = $this->_displayLabels();
        $parts = array();
        foreach ($tokens as $token) {
            if (isset($labels[$token])) {
                $parts[] = $labels[$token];
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
    public function _compareTokens($a, $b)
    {
        $weights = $this->_tokenWeights();
        $wa = isset($weights[$a]) ? $weights[$a] : 100;
        $wb = isset($weights[$b]) ? $weights[$b] : 100;
        if ($wa === $wb) {
            return strcmp($a, $b);
        }
        return ($wa < $wb) ? -1 : 1;
    }

    /**
     * Сортировка фраз: более длинные применяются первыми.
     *
     * @param string $a
     * @param string $b
     * @return int
     */
    public function _comparePhraseKeys($a, $b)
    {
        return strlen($b) - strlen($a);
    }

    /**
     * Регистронезависимое приведение с поддержкой UTF-8.
     *
     * @param string $s
     * @return string
     */
    protected function _lower($s)
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
     *
     * @return array
     */
    protected function _phraseMap()
    {
        return array(
            // Общий бассейн, проходящий вдоль номеров (выход прямо в бассейн)
            'access to outdoor pool' => 'swimup',
            'access to pool'  => 'swimup',
            'pool access'     => 'swimup',
            'swim up'         => 'swimup',
            // Индивидуальный бассейн в номере/на вилле
            'private pool'    => 'privatepool',
            'plunge pool'     => 'privatepool',
            'own pool'        => 'privatepool',
            'с бассейном'     => 'privatepool',
            'частным бассейном' => 'privatepool',
            'собственным бассейном' => 'privatepool',
            // "pool or sea view" должна сработать раньше "sea view"/"pool view"
            'pool or sea view' => 'poolview seaview',
            'sea or pool view' => 'seaview poolview',
            'side sea view'   => 'seaview',
            'вид на бассейн'  => 'poolview',
            'с видом на море' => 'seaview',
            'bed and breakfast' => '',
            'all inclusive'   => '',
            'вид на город'    => 'cityview',
            'mountain view'   => 'mountainview',
            'вид на море'     => 'seaview',
            'вид на горы'     => 'mountainview',
            'вид на сад'      => 'gardenview',
            'junior suite'    => 'juniorsuite',
            'джуниор сюит'    => 'juniorsuite',
            'для некурящих'   => 'nonsmoking',
            'полулюкс'        => 'juniorsuite',
            'garden view'     => 'gardenview',
            'ocean view'      => 'seaview',
            'run of house'    => 'roh',
            'one bedroom'     => '1bedroom',
            'two bedroom'     => '2bedroom',
            '1 bedroom'       => '1bedroom',
            '2 bedroom'       => '2bedroom',
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
     *
     * @return array
     */
    protected function _tokenCombos()
    {
        return array(
            array(array('junior', 'suite'), 'juniorsuite'),
        );
    }

    /**
     * Одиночные токены: синонимы и аббревиатуры -> канонический токен.
     *
     * @return array
     */
    protected function _synonymMap()
    {
        return array(
            // Вместимость / тип размещения
            'sgl' => 'single', 'sngl' => 'single', 'одноместный' => 'single',
            'dbl' => 'double', 'dble' => 'double', 'двухместный' => 'double',
            'двухместная' => 'double',
            'casal' => 'double', // португальское "двуспальная кровать"
            'twn' => 'twin', 'твин' => 'twin',
            'trpl' => 'triple', 'tpl' => 'triple', 'трехместный' => 'triple',
            'трёхместный' => 'triple',
            'qdpl' => 'quad', 'quadruple' => 'quad', 'четырехместный' => 'quad',
            'четырёхместный' => 'quad',
            'fam' => 'family', 'семейный' => 'family',

            // Класс номера
            'std' => 'standard', 'стандарт' => 'standard',
            'стандартный' => 'standard', 'стандартная' => 'standard',
            'sup' => 'superior', 'супериор' => 'superior',
            'dlx' => 'deluxe', 'делюкс' => 'deluxe',
            'люкс' => 'suite', 'сюит' => 'suite', 'suit' => 'suite',
            'improved' => 'superior', 'улучшенный' => 'superior',
            'улучшенная' => 'superior',
            'exec' => 'executive',
            'econom' => 'economy', 'эконом' => 'economy',
            'премиум' => 'premium',
            'президентский' => 'presidential',
            'apt' => 'apartment', 'apts' => 'apartment',
            'апартамент' => 'apartment', 'апартаменты' => 'apartment',
            'студия' => 'studio', 'студио' => 'studio',
            'бунгало' => 'bungalow',
            'вилла' => 'villa',
            'коттедж' => 'cottage',

            // Кровати
            'кинг' => 'king',
            'квин' => 'queen',

            // Виды (аббревиатуры)
            'sv' => 'seaview',
            'gv' => 'gardenview',
            // Одиночный "pool" вне фраз ("Pool Villa", "Villa with Pool") означает
            // индивидуальный бассейн; вид на бассейн всегда пишется как "pool view"
            'pool' => 'privatepool',
            'бассейн' => 'privatepool', 'бассейном' => 'privatepool',

            // Атрибуты
            'balc' => 'balcony', 'балкон' => 'balcony', 'балконом' => 'balcony',
            'терраса' => 'terrace', 'террасой' => 'terrace',

            // Единственное/множественное число
            'suites' => 'suite',
            'apartments' => 'apartment',
            'villas' => 'villa',
            'studios' => 'studio',
            'duplexes' => 'duplex',
        );
    }

    /**
     * Слова, не несущие категорийного смысла, — отбрасываются.
     *
     * @return array
     */
    protected function _stopWords()
    {
        return array_flip(array(
            'room', 'rooms', 'номер', 'номера', 'комната',
            'with', 'and', 'or', 'the', 'a', 'an', 'in', 'of', 'for', 'to',
            'с', 'и', 'или', 'на', 'в', 'для', 'без',
            'bed', 'beds', 'кровать', 'кроватью', 'кровати',
            'adults', 'adult', 'взрослых', 'взрослый',
            'kids', 'kid', 'child', 'children', 'детская',
            'sofa', 'large', 'extra', 'bunk', 'size',
            'free', 'wifi', 'internet',
            'capacity', 'view', 'side', 'outdoor',
            'only', 'new', 'main', 'building',
        ));
    }

    /**
     * Критические классы токенов. Внутри одного класса значения должны
     * совпадать точно, чтобы группы можно было объединить.
     *
     * @return array
     */
    protected function _tokenClasses()
    {
        return array(
            // Класс/уровень номера
            'economy' => 'grade', 'standard' => 'grade', 'superior' => 'grade',
            'deluxe' => 'grade', 'premium' => 'grade', 'suite' => 'grade',
            'juniorsuite' => 'grade', 'presidential' => 'grade',
            'executive' => 'grade', 'apartment' => 'grade', 'studio' => 'grade',
            'bungalow' => 'grade', 'villa' => 'grade', 'cottage' => 'grade',
            'family' => 'grade', 'duplex' => 'grade', 'roh' => 'grade',
            // Вид из окна
            'seaview' => 'view', 'gardenview' => 'view', 'cityview' => 'view',
            'poolview' => 'view', 'mountainview' => 'view',
            // Вместимость (double/twin/queen/king — "кроватные", не здесь)
            'single' => 'capacity', 'triple' => 'capacity', 'quad' => 'capacity',
            // Бассейн: swim-up (выход в общий бассейн) и индивидуальный бассейн —
            // разные категории, точное сравнение класса не даст им слиться
            'swimup' => 'access', 'privatepool' => 'access',
        );
    }

    /**
     * "Кроватные" токены: сохраняются в ключе и названии категории,
     * но не участвуют в сравнении групп.
     *
     * @return array
     */
    protected function _beddingTokens()
    {
        return array('double' => true, 'twin' => true, 'queen' => true, 'king' => true);
    }

    /**
     * Смысловой вес токена для порядка в ключе и названии категории.
     * Меньше — важнее (идёт первым).
     *
     * @return array
     */
    protected function _tokenWeights()
    {
        return array(
            // Класс номера
            'economy' => 10, 'standard' => 10, 'superior' => 10, 'deluxe' => 10,
            'premium' => 10, 'suite' => 10, 'juniorsuite' => 10,
            'presidential' => 10, 'executive' => 11, 'apartment' => 10,
            'studio' => 10, 'bungalow' => 10, 'villa' => 10, 'cottage' => 10,
            'family' => 12, 'duplex' => 12, 'roh' => 10,
            // Вместимость
            'single' => 20, 'double' => 20, 'twin' => 20, 'triple' => 20,
            'quad' => 20,
            // Кровати / спальни
            'king' => 30, 'queen' => 30, '1bedroom' => 30, '2bedroom' => 30,
            // Виды и доступ к бассейну
            'seaview' => 40, 'gardenview' => 40, 'cityview' => 40,
            'poolview' => 40, 'mountainview' => 40, 'swimup' => 45,
            'privatepool' => 45,
            // Атрибуты
            'balcony' => 50, 'terrace' => 50, 'nonsmoking' => 60,
        );
    }

    /**
     * Красивые подписи для канонических токенов в названии категории.
     *
     * @return array
     */
    protected function _displayLabels()
    {
        return array(
            'seaview'      => 'Sea View',
            'gardenview'   => 'Garden View',
            'cityview'     => 'City View',
            'poolview'     => 'Pool View',
            'mountainview' => 'Mountain View',
            'juniorsuite'  => 'Junior Suite',
            'nonsmoking'   => 'Non-Smoking',
            'swimup'       => 'Swim-Up',
            'privatepool'  => 'Private Pool',
            '1bedroom'     => 'One Bedroom',
            '2bedroom'     => 'Two Bedroom',
            'roh'          => 'Run of House',
        );
    }
}
