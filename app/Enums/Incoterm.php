<?php

namespace App\Enums;

/**
 * The 11 Incoterms 2020 rules — a fixed, standardized list (unlike e.g. `industry`, which is
 * open-ended), so an enum is the right fit here.
 */
enum Incoterm: string
{
    case EXW = 'exw';
    case FCA = 'fca';
    case CPT = 'cpt';
    case CIP = 'cip';
    case DAP = 'dap';
    case DPU = 'dpu';
    case DDP = 'ddp';
    case FAS = 'fas';
    case FOB = 'fob';
    case CFR = 'cfr';
    case CIF = 'cif';
}
