<?php

namespace App\Discussion\Values;

/** How a tag filter combines its picked values (see FiltersByTags). */
enum TagFilterMode: string
{
    /** A row matches every picked category (any picked value within one). */
    case Cut = 'cut';

    /** Any one picked value is enough. */
    case Merge = 'merge';
}
