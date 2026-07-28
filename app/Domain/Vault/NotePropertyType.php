<?php

namespace App\Domain\Vault;

enum NotePropertyType: string
{
    case STRING = 'string';
    case NUMERIC = 'numeric';
    case BOOLEAN = 'boolean';
    case DATETIME = 'datetime';
    case LIST = 'list';
    case JSON = 'json';
}
