<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Infection;

/** شناسه‌ی پایدار یک mutant بین اجراهای مختلف Infection (گزارش JSON شناسه ندارد). */
final class MutantKey
{
    public static function of(Mutant $mutant): string
    {
        return substr(md5($mutant->mutator . '|' . $mutant->line . '|' . implode("\n", $mutant->changedLines())), 0, 12);
    }
}
