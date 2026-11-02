<?php

namespace App\Enums;

enum QuoteLineType: string
{
    case Item = 'item';
    case Section = 'section';
    case Text = 'text';
}
