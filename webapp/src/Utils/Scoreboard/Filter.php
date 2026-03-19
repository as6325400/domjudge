<?php declare(strict_types=1);

namespace App\Utils\Scoreboard;

class Filter
{
    final public const PARTICIPATION_LIVE = 'live';
    final public const PARTICIPATION_VIRTUAL = 'virtual';
    final public const PARTICIPATION_ALL = 'all';

    /**
     * @param int[] $affiliations
     * @param string[] $countries
     * @param int[] $categories
     * @param int[] $teams
     * @param string $participationType One of 'live', 'virtual', 'all'
     */
    public function __construct(
        public array $affiliations = [],
        public array $countries = [],
        public array $categories = [],
        public array $teams = [],
        public string $participationType = self::PARTICIPATION_LIVE,
    ) {}

    /**
     * Get a string to display on what has been filtered.
     */
    public function getFilteredOn(): string
    {
        $filteredOn = [];
        if ($this->affiliations) {
            $filteredOn[] = 'affiliations';
        }
        if ($this->countries) {
            $filteredOn[] = 'countries';
        }
        if ($this->categories) {
            $filteredOn[] = 'categories';
        }
        if ($this->teams) {
            $filteredOn[] = 'teams';
        }

        return implode(', ', $filteredOn);
    }
}
