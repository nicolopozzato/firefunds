<?php

namespace App\Core\Doctrine;

use Doctrine\ORM\Query\AST\ASTException;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * CONVERTED(amount, currency, day, target, user) in DQL: the amount (in
 * `currency`, on `day`) worth in the `target` currency at that day's rate for
 * that user (the database function app_convert). SUM(CONVERTED(t.amount,
 * t.currency, t.occurredAt, :to, :user)) adds up amounts of different
 * currencies, each at its own day's rate.
 */
class ConvertedFunction extends FunctionNode
{
    private Node $amount;
    private Node $currency;
    private Node $day;
    private Node $target;
    private Node $user;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->amount = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->currency = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->day = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->target = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->user = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    /** @throws ASTException */
    public function getSql(SqlWalker $sqlWalker): string
    {
        return \sprintf(
            'app_convert(%s, %s, CAST(%s AS varchar), CAST(%s AS date), CAST(%s AS uuid))',
            $this->amount->dispatch($sqlWalker),
            $this->currency->dispatch($sqlWalker),
            $this->target->dispatch($sqlWalker),
            $this->day->dispatch($sqlWalker),
            $this->user->dispatch($sqlWalker),
        );
    }
}
