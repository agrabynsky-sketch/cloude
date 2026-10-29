<?php
/**
 * The availability, rates and restrictions arrays of a Unit.Travel ARI message.
 */
class Exely_AriParts
{
    public static $kinds = array('availability', 'rates', 'restrictions');

    public $availability = array();
    public $rates = array();
    public $restrictions = array();

    /** @var Exely_Issue[] */
    public $warnings = array();

    public function isEmpty()
    {
        return !$this->availability && !$this->rates && !$this->restrictions;
    }

    /**
     * Split into message bodies of at most $maxItems items each.
     *
     * @param  int $maxItems
     * @return array[]
     */
    public function chunks($maxItems)
    {
        $items = array();
        foreach (self::$kinds as $kind) {
            foreach ($this->$kind as $item) {
                $items[] = array($kind, $item);
            }
        }
        $chunks = array();
        foreach (array_chunk($items, max(1, (int) $maxItems)) as $slice) {
            $chunk = array();
            foreach ($slice as $entry) {
                $chunk[$entry[0]][] = $entry[1];
            }
            $chunks[] = $chunk;
        }
        return $chunks;
    }
}
