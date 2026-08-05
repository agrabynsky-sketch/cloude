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

/**
 * Главная функция.
 *
 * @param array $supplierRooms массив вида:
 *        array(
 *            'SupplierA' => array('Standard DBL Room', 'Suite Sea View', ...),
 *            'SupplierB' => array('STD Double', ...),
 *        )
 * @param float $similarityThreshold порог сходства Жаккара (0..1) для
 *        объединения неполностью совпадающих наборов токенов
 * @param array $noiseWords дополнительные шумовые слова, специфичные для
 *        ваших поставщиков (например, название отеля: array('jaz', 'bluemarine')) —
 *        они будут отброшены при нормализации
 *
 * @return array массив категорий:
 *        array(
 *            'deluxe-family-poolview' => array(
 *                'category' => 'Deluxe Family Pool View', // сгенерированное название
 *                'tokens'   => array('deluxe', 'family', 'poolview'),
 *                'rooms'    => array(
 *                    array('supplier' => 'SupplierA', 'name' => 'FAMILY DELUXE POOL VIEW'),
 *                    array('supplier' => 'SupplierB', 'name' => 'Deluxe Family Room (Pool View)'),
 *                ),
 *            ),
 *            ...
 *        )
 */
function groupHotelRooms(array $supplierRooms, $similarityThreshold = 0.6, array $noiseWords = array())
{
    $groups = array();
    $extraStop = array();
    foreach ($noiseWords as $word) {
        $extraStop[roomGrouperLower($word)] = true;
    }

    foreach ($supplierRooms as $supplier => $roomNames) {
        if (!is_array($roomNames)) {
            continue;
        }
        foreach ($roomNames as $roomName) {
            // $tokens — в порядке появления слов в названии (для показа).
            $tokens = roomGrouperNormalize($roomName, $extraStop);
            if (count($tokens) === 0) {
                // Подстраховка: нормализация всегда возвращает хотя бы низшую
                // категорию, но на всякий случай не оставляем пустой набор.
                $tokens = array(roomGrouperDefaultGrade());
            }

            // Отсортированная копия — только для ключа (чтобы "Sea View
            // Superior" и "Superior Sea View" попадали в одну группу).
            $sorted = $tokens;
            usort($sorted, 'roomGrouperCompareTokens');

            $key = implode('-', $sorted);
            $signature = roomGrouperSignature($sorted);
            $simTokens = roomGrouperSimilarityTokens($sorted);

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
                    $score = roomGrouperJaccard($simTokens, $group['sim_tokens']);
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
                    'category'   => roomGrouperBuildCategoryName($tokens),
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
function roomGrouperNormalize($name, array $extraStop = array())
{
    $s = roomGrouperLower($name);

    // HTML-сущности из выгрузок поставщиков ("&amp;" -> "&"), иначе
    // после чистки пунктуации остаётся мусорный токен "amp".
    $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
    // Символы площади ² / ³ -> цифры, чтобы "m²" стало "m2".
    $s = str_replace(array('²', '³'), array('2', '3'), $s);

    // Скобки, содержащие ТОЛЬКО конфигурацию кроватей, вырезаются целиком:
    // "(1 QUEEN BED)", "(2 TWIN BEDS OR 1 QUEEN BED)".
    // Скобки с содержательными словами ("(POOL VIEW)", "(DELUXE)") остаются.
    $s = roomGrouperStripBedConfig($s);

    // Пунктуацию и разделители — в пробелы
    $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    if ($clean === null) { // на случай отсутствия PCRE-UTF8
        $clean = preg_replace('/[^a-z0-9]+/i', ' ', $s);
    }
    $s = ' ' . trim($clean) . ' ';

    // Площадь номера ("25 sqm", "25sqm", "300 sq ft", "25 m2") — убираем.
    $noArea = preg_replace('/(?<= )\d+ ?(?:sq ?m|sq ?ft|sqmt|sqmts|sqm|sqft|m2|ft2)(?= )/', ' ', $s);
    if ($noArea !== null) {
        $s = ' ' . trim(preg_replace('/\s+/', ' ', $noArea)) . ' ';
    }

    // Многословные обороты -> один токен (до разбиения на слова).
    // Более длинные фразы применяются первыми, чтобы "pool or sea view"
    // сработала раньше, чем "sea view" или "pool view".
    $phrases = roomGrouperPhraseMap();
    uksort($phrases, 'roomGrouperComparePhraseKeys');
    foreach ($phrases as $phrase => $canonical) {
        $s = str_replace(' ' . $phrase . ' ', ' ' . $canonical . ' ', $s);
    }

    // "exclusive" в НАЧАЛЕ названия — значимый признак категории, его
    // сохраняем; в середине это обычно рекламное слово (см. стоп-слова).
    $leadingExclusive = (strpos($s, ' exclusive ') === 0);

    // Любой "<слово> view" (кроме уже распознанных видов) склеиваем в
    // единый токен-вид: "Eiffel View" -> eiffelview, "land view" ==
    // "landview". Так значимые/лендмарк-виды сохраняются и не дробятся.
    $s = roomGrouperCollapseViews($s);

    // "N bedroom(s)" / "one/two... bedroom(s)" -> токен Nbedroom, чтобы
    // количество спален не терялось ("2 Bedrooms") и не дописывалось.
    $s = roomGrouperCollapseBedrooms($s);

    $rawTokens = preg_split('/\s+/u', trim($s));
    if ($rawTokens === false) {
        $rawTokens = explode(' ', trim($s));
    }

    $synonyms  = roomGrouperSynonymMap();
    $stopWords = roomGrouperStopWords();

    $tokens = array();
    foreach ($rawTokens as $token) {
        if ($token === '' || isset($stopWords[$token]) || isset($extraStop[$token])) {
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

    // Тип кровати не участвует в группировке: номера с опциями
    // "King or Twin", "Double/Twin", "1 King Or 2 Twin" не должны
    // дробиться по кроватям и подписываться одним типом.
    foreach (roomGrouperBeddingTokens() as $bedToken => $ignored) {
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
    if (!roomGrouperHasGrade($tokens)) {
        $tokens = array(roomGrouperDefaultGrade() => true) + $tokens;
    }

    // Порядок токенов = порядок появления в названии (правка 30).
    // Сортировка для ключа/сигнатуры делается в groupHotelRooms().
    return array_keys($tokens);
}

/**
 * Вырезает скобки, содержащие только конфигурацию кроватей.
 */
function roomGrouperStripBedConfig($s)
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
 */
function roomGrouperCollapseViews($s)
{
    $result = preg_replace_callback(
        '/(?<= )([a-z0-9]+) view(?= )/',
        'roomGrouperViewGlueCallback',
        $s
    );
    return ($result === null) ? $s : $result;
}

/** Колбэк склейки: возвращает "<слово>view" или исходное совпадение. */
function roomGrouperViewGlueCallback($m)
{
    $word = $m[1];
    if (roomGrouperIsStructuralWord($word)) {
        return $m[0]; // "Superior View", "Family View" — не склеиваем
    }
    return $word . 'view';
}

/** Слово относится к известным (класс/вид/вместимость/кровать/стоп/число). */
function roomGrouperIsStructuralWord($word)
{
    if (preg_match('/^\d+$/', $word)) {
        return true;
    }
    $stop = roomGrouperStopWords();
    if (isset($stop[$word])) {
        return true;
    }
    $syn = roomGrouperSynonymMap();
    if (isset($syn[$word])) {
        return true;
    }
    $classes = roomGrouperTokenClasses();
    if (isset($classes[$word])) {
        return true;
    }
    $bedding = roomGrouperBeddingTokens();
    if (isset($bedding[$word])) {
        return true;
    }
    return false;
}

/**
 * "N bedroom(s)" и словесные формы ("one/two/three bedroom(s)")
 * приводит к единому токену "Nbedroom" (2bedroom, 3bedroom...).
 */
function roomGrouperCollapseBedrooms($s)
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
function roomGrouperHasGrade(array $tokens)
{
    $classes = roomGrouperTokenClasses();
    foreach ($tokens as $token => $ignored) {
        if (isset($classes[$token]) && $classes[$token] === 'grade') {
            return true;
        }
    }
    return false;
}

/**
 * Токены, важные для сходства: всё, кроме "кроватных" (double/twin/queen/king).
 * Кроватные токены остаются в ключе и названии категории, но не влияют
 * на сравнение групп: "Superior Sea View" == "Superior Twin Sea View".
 */
function roomGrouperSimilarityTokens(array $tokens)
{
    $bedding = roomGrouperBeddingTokens();
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
 */
function roomGrouperSignature(array $tokens)
{
    $classes = roomGrouperTokenClasses();
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
 * Порядок: класс номера, вместимость, кровати, вид, атрибуты.
 */
function roomGrouperBuildCategoryName(array $tokens)
{
    $labels = roomGrouperDisplayLabels();
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
 */
function roomGrouperCompareTokens($a, $b)
{
    $wa = roomGrouperTokenWeight($a);
    $wb = roomGrouperTokenWeight($b);
    if ($wa === $wb) {
        return strcmp($a, $b);
    }
    return ($wa < $wb) ? -1 : 1;
}

/** Вес токена, включая динамические ("Nbedroom", лендмарк-виды). */
function roomGrouperTokenWeight($token)
{
    $weights = roomGrouperTokenWeights();
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

/** Сортировка фраз: более длинные применяются первыми. */
function roomGrouperComparePhraseKeys($a, $b)
{
    return strlen($b) - strlen($a);
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

/**
 * Многословные обороты -> канонический токен (применяются до разбиения).
 * ВАЖНО: более длинные фразы должны идти раньше коротких с тем же началом.
 */
function roomGrouperPhraseMap()
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
        // Те же формы, но где "seaview" написано слитно (см. правку 29)
        'partial seaview'    => 'partialseaview',
        'partial oceanview'  => 'partialseaview',
        'side seaview'       => 'partialseaview',
        'lateral seaview'    => 'partialseaview',
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
        'sgl' => 'single', 'sngl' => 'single',
        'dbl' => 'double', 'dble' => 'double',
        'casal' => 'double', // португальское "двуспальная кровать"
        'twn' => 'twin',
        'dwb' => 'double', 'twb' => 'twin', // DWB=Double Bed, TWB=Twin Bed
        'trpl' => 'triple', 'tpl' => 'triple',
        'qdpl' => 'quad', 'quadruple' => 'quad',
        'fam' => 'family',

        // Класс номера
        'std' => 'standard',
        'standart' => 'standard', // частые опечатки
        'stadard' => 'standard',
        'standarts' => 'standard', 'standards' => 'standard',
        'sup' => 'superior',
        'dlx' => 'deluxe', 'delux' => 'deluxe',
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
function roomGrouperStopWords()
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
        'refundable', 'nonrefundable', 'nonref', 'refund', 'rate',
        // Комментарии: "(bed type is subject to availability)",
        // "(extra bed not included)", "upon request"
        'is', 'are', 'subject', 'availability', 'available', 'type', 'types',
        'not', 'excluded', 'on', 'upon', 'request', 'amp',
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
function roomGrouperTokenClasses()
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
function roomGrouperBeddingTokens()
{
    return array(
        'single' => true, 'double' => true, 'twin' => true,
        'queen' => true, 'king' => true,
    );
}

/**
 * Самая низшая категория номера. Присваивается номерам, названным
 * только по типу кровати ("Double", "Twin", "Double/Twin Room").
 */
function roomGrouperDefaultGrade()
{
    return 'standard';
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
        'presidential' => 10, 'executive' => 11, 'exclusive' => 11,
        'luxury' => 10, 'spectacular' => 10, 'premier' => 10,
        'elite' => 10, 'pavilion' => 10,
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
function roomGrouperDisplayLabels()
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
