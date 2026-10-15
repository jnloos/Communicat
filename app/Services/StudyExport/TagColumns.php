<?php

namespace App\Services\StudyExport;

use App\Models\TagCategory;
use App\Models\TagValue;
use App\Services\Tags\TagNames;
use Illuminate\Support\Collection;

/**
 * One CSV column per tag category, named like the category. A category named
 * like a fixed column of the file gets the prefix "tag "; one named like an
 * earlier category (ignoring case and surrounding space, as tag names match
 * everywhere) gets its id as a suffix. No header overwrites another.
 */
final class TagColumns
{
    /** @var array<int, string> header by category id, in category order */
    private readonly array $headers;

    /**
     * @param  Collection<int, TagCategory>  $categories  in column order
     * @param  list<string>  $fixed  the file's other columns
     */
    public function __construct(Collection $categories, array $fixed)
    {
        $fixed = array_map(TagNames::normal(...), $fixed);
        $taken = $fixed;
        $headers = [];

        foreach ($categories as $category) {
            $header = in_array(TagNames::normal($category->name), $fixed, true) ? 'tag '.$category->name : $category->name;

            if (in_array(TagNames::normal($header), $taken, true)) {
                $header = trim($category->name)." #{$category->id}";
            }

            $headers[$category->id] = $header;
            $taken[] = TagNames::normal($header);
        }

        $this->headers = $headers;
    }

    /** @return list<string> */
    public function headers(): array
    {
        return array_values($this->headers);
    }

    /**
     * The cells of one tagged row: the value's name per column, null where
     * the row has no value in that category.
     *
     * @param  Collection<int, TagValue>  $tagValues
     * @return array<string, ?string>
     */
    public function cells(Collection $tagValues): array
    {
        $byCategory = $tagValues->keyBy('tag_category_id');

        return array_map(fn (int $categoryId) => $byCategory[$categoryId]->name ?? null, array_flip($this->headers));
    }
}
