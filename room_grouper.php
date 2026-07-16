<?php
/**
 * Группировка названий отельных номеров от разных поставщиков
 * в уникальные семантические категории "на лету", без заранее
 * подготовленного маппинга между поставщиками.
 *
 * Идея алгоритма:
 *  1. Название номера нормализуется: нижний регистр, убирается пунктуация,
 *     многословные обороты склеиваются ("sea view" -> "seaview").
 *  2. Каждый токен приводится к каноническому виду по словарю синонимов
 *     и аббревиатур (dbl -> double, "двухместный" -> double, std -> standard...).
 *  3. Стоп-слова ("room", "номер", "with", ...) отбрасываются.
 *  4. Оставшиеся канонические токены сортируются по смысловому весу
 *     (категория -> вместимость -> кровать -> атрибуты) и образуют ключ группы.
 *  5. Если точного ключа ещё нет, ищется существующая группа с достаточным
 *     сходством наборов токенов (коэффициент Жаккара) — так близкие названия
 *     объединяются, даже когда наборы токенов совпадают не полностью.
 *  6. Название категории собирается из ключевых слов группы.
 *
 * Код совместим с PHP 5.2+ (не используются короткий синтаксис массивов,
 * замыкания и пр.), работает и на PHP 7/8.
 */

/**
 * Главная функция.
 *
 * @param array $supplierRooms массив вида:
 *        array(
 *            'ИмяПоставщика1' => array('Standard DBL Room', 'Suite Sea View', ...),
 *            'ИмяПоставщика2' => array('Двухместный стандарт', ...),
 *        )
 * @param float $similarityThreshold порог сходства Жаккара (0..1) для
 *        объединения неполностью совпадающих наборов токенов
 *
 * @return array массив категорий:
 *        array(
 *            'double-standard' => array(
 *                'category' => 'Standard Double',      // сгенерированное название
 *                'tokens'   => array('standard', 'double'),
 *                'rooms'    => array(
 *                    array('supplier' => 'ИмяПоставщика1', 'name' => 'Standard DBL Room'),
 *                    array('supplier' => 'ИмяПоставщика2', 'name' => 'Двухместный стандарт'),
 *                ),
 *            ),
 *            ...
 *        )
 */
function groupHotelRooms(array $supplierRooms, $similarityThreshold = 0.6)
{
    $groups = array();

    foreach ($supplierRooms as $supplier => $roomNames) {
        if (!is_array($roomNames)) {
            continue;
        }
        foreach ($roomNames as $roomName) {
            $tokens = roomGrouperNormalize($roomName);
            if (count($tokens) === 0) {
                // Ничего осмысленного не извлекли — отдельная категория "как есть"
                $tokens = array(roomGrouperLower(trim($roomName)));
            }

            $key = implode('-', $tokens);

            // 1. Точное совпадение ключа
            if (!isset($groups[$key])) {
                // 2. Поиск похожей группы по коэффициенту Жаккара
                $bestKey = null;
                $bestScore = 0.0;
                foreach ($groups as $existingKey => $group) {
                    $score = roomGrouperJaccard($tokens, $group['tokens']);
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
                    'category' => roomGrouperBuildCategoryName($tokens),
                    'tokens'   => $tokens,
                    'rooms'    => array(),
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
function roomGrouperNormalize($name)
{
    $s = roomGrouperLower($name);

    // Пунктуацию и разделители — в пробелы
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    if ($s === null) { // на случай отсутствия PCRE-UTF8
        $s = preg_replace('/[^a-z0-9а-яё]+/i', ' ', roomGrouperLower($name));
    }
    $s = ' ' . trim($s) . ' ';

    // Многословные обороты -> один токен (до разбиения на слова)
    foreach (roomGrouperPhraseMap() as $phrase => $canonical) {
        $s = str_replace(' ' . $phrase . ' ', ' ' . $canonical . ' ', $s);
    }

    $rawTokens = preg_split('/\s+/u', trim($s));
    if ($rawTokens === false) {
        $rawTokens = explode(' ', trim($s));
    }

    $synonyms  = roomGrouperSynonymMap();
    $stopWords = roomGrouperStopWords();

    $tokens = array();
    foreach ($rawTokens as $token) {
        if ($token === '' || isset($stopWords[$token])) {
            continue;
        }
        // Числа сами по себе (например "2" из "2 adults") не несут категории
        if (preg_match('/^\d+$/', $token)) {
            continue;
        }
        if (isset($synonyms[$token])) {
            $token = $synonyms[$token];
        }
        if ($token === '' || isset($stopWords[$token])) {
            continue;
        }
        $tokens[$token] = true; // уникальность
    }

    // Комбинации токенов, образующие одно понятие независимо от порядка слов
    // ("Junior Suite" и "Suite Junior" -> juniorsuite)
    foreach (roomGrouperTokenCombos() as $combo) {
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
    usort($tokens, 'roomGrouperCompareTokens');

    return $tokens;
}

/**
 * Коэффициент Жаккара для двух наборов токенов: |A ∩ B| / |A ∪ B|.
 */
function roomGrouperJaccard(array $a, array $b)
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
 * Порядок: класс номера, вместимость, тип кровати, атрибуты.
 */
function roomGrouperBuildCategoryName(array $tokens)
{
    $labels = roomGrouperDisplayLabels();
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
 */
function roomGrouperCompareTokens($a, $b)
{
    $weights = roomGrouperTokenWeights();
    $wa = isset($weights[$a]) ? $weights[$a] : 100;
    $wb = isset($weights[$b]) ? $weights[$b] : 100;
    if ($wa === $wb) {
        return strcmp($a, $b);
    }
    return ($wa < $wb) ? -1 : 1;
}

/** Регистронезависимое приведение с поддержкой UTF-8. */
function roomGrouperLower($s)
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

/** Многословные обороты -> канонический токен (применяются до разбиения). */
function roomGrouperPhraseMap()
{
    return array(
        'sea view'        => 'seaview',
        'ocean view'      => 'seaview',
        'вид на море'     => 'seaview',
        'с видом на море' => 'seaview',
        'garden view'     => 'gardenview',
        'вид на сад'      => 'gardenview',
        'city view'       => 'cityview',
        'вид на город'    => 'cityview',
        'pool view'       => 'poolview',
        'вид на бассейн'  => 'poolview',
        'mountain view'   => 'mountainview',
        'вид на горы'     => 'mountainview',
        'de luxe'         => 'deluxe',
        'king size'       => 'king',
        'junior suite'    => 'juniorsuite',
        'джуниор сюит'    => 'juniorsuite',
        'полулюкс'        => 'juniorsuite',
        'non smoking'     => 'nonsmoking',
        'для некурящих'   => 'nonsmoking',
        'run of house'    => 'roh',
        'one bedroom'     => '1bedroom',
        'two bedroom'     => '2bedroom',
        '1 bedroom'       => '1bedroom',
        '2 bedroom'       => '2bedroom',
        'half board'      => '', // питание не влияет на категорию номера
        'full board'      => '',
        'all inclusive'   => '',
        'bed and breakfast' => '',
    );
}

/**
 * Наборы токенов, которые вместе образуют одно понятие (порядок слов не важен).
 * Формат: array(array(составные_токены), 'канонический_токен').
 */
function roomGrouperTokenCombos()
{
    return array(
        array(array('junior', 'suite'), 'juniorsuite'),
    );
}

/** Одиночные токены: синонимы и аббревиатуры -> канонический токен. */
function roomGrouperSynonymMap()
{
    return array(
        // Вместимость / тип размещения
        'sgl' => 'single', 'sngl' => 'single', 'одноместный' => 'single',
        'dbl' => 'double', 'dble' => 'double', 'двухместный' => 'double',
        'двухместная' => 'double',
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
        'econom' => 'economy', 'эконом' => 'economy',
        'премиум' => 'premium',
        'президентский' => 'presidential',
        'apt' => 'apartment', 'apts' => 'apartment',
        'апартамент' => 'apartment', 'апартаменты' => 'apartment',
        'studio' => 'studio', 'студия' => 'studio', 'студио' => 'studio',
        'bungalow' => 'bungalow', 'бунгало' => 'bungalow',
        'villa' => 'villa', 'вилла' => 'villa',
        'cottage' => 'cottage', 'коттедж' => 'cottage',

        // Кровати
        'кинг' => 'king',
        'queen' => 'queen',

        // Атрибуты
        'balc' => 'balcony', 'балкон' => 'balcony', 'балконом' => 'balcony',
        'terrace' => 'terrace', 'терраса' => 'terrace', 'террасой' => 'terrace',
        'nonsmoking' => 'nonsmoking',

        // Единственное/множественное число
        'suites' => 'suite',
        'apartments' => 'apartment',
        'villas' => 'villa',
        'studios' => 'studio',
    );
}

/** Слова, не несущие категорийного смысла, — отбрасываются. */
function roomGrouperStopWords()
{
    return array_flip(array(
        'room', 'rooms', 'номер', 'номера', 'комната',
        'with', 'and', 'or', 'the', 'a', 'an', 'in', 'of', 'for',
        'с', 'и', 'или', 'на', 'в', 'для', 'без',
        'bed', 'beds', 'кровать', 'кроватью', 'кровати',
        'adults', 'adult', 'взрослых', 'взрослый',
        'only', 'new', 'main', 'building',
    ));
}

/**
 * Смысловой вес токена для порядка в ключе и названии категории.
 * Меньше — важнее (идёт первым).
 */
function roomGrouperTokenWeights()
{
    return array(
        // Класс номера
        'economy' => 10, 'standard' => 10, 'superior' => 10, 'deluxe' => 10,
        'premium' => 10, 'suite' => 10, 'juniorsuite' => 10,
        'presidential' => 10, 'apartment' => 10, 'studio' => 10,
        'bungalow' => 10, 'villa' => 10, 'cottage' => 10, 'family' => 10,
        'roh' => 10,
        // Вместимость
        'single' => 20, 'double' => 20, 'twin' => 20, 'triple' => 20,
        'quad' => 20,
        // Кровати / спальни
        'king' => 30, 'queen' => 30, '1bedroom' => 30, '2bedroom' => 30,
        // Виды и атрибуты
        'seaview' => 40, 'gardenview' => 40, 'cityview' => 40,
        'poolview' => 40, 'mountainview' => 40,
        'balcony' => 50, 'terrace' => 50, 'nonsmoking' => 60,
    );
}

/** Красивые подписи для канонических токенов в названии категории. */
function roomGrouperDisplayLabels()
{
    return array(
        'seaview'      => 'Sea View',
        'gardenview'   => 'Garden View',
        'cityview'     => 'City View',
        'poolview'     => 'Pool View',
        'mountainview' => 'Mountain View',
        'juniorsuite'  => 'Junior Suite',
        'nonsmoking'   => 'Non-Smoking',
        '1bedroom'     => 'One Bedroom',
        '2bedroom'     => 'Two Bedroom',
        'roh'          => 'Run of House',
    );
}
