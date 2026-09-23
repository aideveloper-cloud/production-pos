<?php

namespace App\Enums;

enum StockLedgerMovement: string
{
    case Receive = 'RECEIVE';
    case Issue = 'ISSUE';
    case Consumption = 'CONSUMPTION';
    case TransferIn = 'TRANSFER_IN';
    case TransferOut = 'TRANSFER_OUT';
    case Adjustment = 'ADJUSTMENT';
    case Count = 'COUNT';
}
