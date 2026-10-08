<?php

namespace App\Discussion\Metrics\References;

/** A reference that filters by tag values, so deleted values can be found and dropped. */
interface NamesTagValues
{
    /** @return list<int> */
    public function tagValueIds(): array;

    /** @param  list<int>  $ids */
    public function withoutTagValues(array $ids): static;
}
