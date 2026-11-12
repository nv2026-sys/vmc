<?php

namespace App\Database;

use Illuminate\Database\Query\Grammars\SqlServerGrammar as BaseSqlServerGrammar;

class SqlServerGrammar extends BaseSqlServerGrammar
{
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.vP';
    }
}
