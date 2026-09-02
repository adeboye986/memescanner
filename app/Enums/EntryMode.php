<?php

namespace App\Enums;

enum EntryMode: string
{
    case Signal = 'signal';
    case Confirm = 'confirm';
    case Auto = 'auto';
}
