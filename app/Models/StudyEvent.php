<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Thesis study, see docs/study.md.
 *
 * One line of the monitoring's log: what the watcher or an admin did. The dashboard
 * shows the latest, so an alert is never lost when Discord and mail are both down.
 */
class StudyEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['kind', 'level', 'message'];
}
