<?php

namespace App\Enums;

enum StockCalcStrategy: string
{
    case QtyBased = 'QTY_BASED';
    case ActualWeightBased = 'ACTUAL_WEIGHT_BASED';
    case SpoolWeightBased = 'SPOOL_WEIGHT_BASED';
    case PieceBased = 'PIECE_BASED';
    case SheetBased = 'SHEET_BASED';
}
