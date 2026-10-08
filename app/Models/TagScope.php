<?php

namespace App\Models;

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

    public function label(): string
    {
        return __("tags.scopes.{$this->value}");
    }
}
