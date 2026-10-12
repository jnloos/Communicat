<?php

namespace App\Models;

use App\Discussion\Values\DimensionSource;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Which kind of thing a tag category describes. Each scope belongs to exactly
 * one model class, and the morph type its rows carry in taggables comes from
 * getMorphClass() — never spelled out as a class name.
 */
enum TagScope: string
{
    case Persona = 'persona';
    case Project = 'project';

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Persona => Expert::class,
            self::Project => Project::class,
        };
    }

    public static function forModel(Model $model): self
    {
        foreach (self::cases() as $scope) {
            $class = $scope->modelClass();

            if ($model instanceof $class) {
                return $scope;
            }
        }

        throw new InvalidArgumentException('No tag scope for '.$model::class.'.');
    }

    /** What the statistics page calls the tags of this scope next to a listed category. */
    public function source(): DimensionSource
    {
        return match ($this) {
            self::Persona => DimensionSource::PersonaTag,
            self::Project => DimensionSource::ProjectTag,
        };
    }

    public function label(): string
    {
        return __("tags.scopes.{$this->value}");
    }
}
