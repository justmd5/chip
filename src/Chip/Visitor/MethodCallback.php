<?php
/**
 * Created by PhpStorm.
 * User: phithon
 * Date: 2019/1/20
 * Time: 2:56
 */

namespace Chip\Visitor;


use Chip\BaseVisitor;
use Chip\Code;
use PhpParser\Node;

class MethodCallback extends BaseVisitor
{
    protected $checkNodeClass = [
        Node\Expr\MethodCall::class
    ];

    protected $sensitiveMethodName = [
        'uasort' => [0],
        'uksort' => [0],
        'set_local_infile_handler' => [1],
        'sqlitecreateaggregate' => [1, 2],
        'sqlitecreatecollation' => [1],
        'sqlitecreatefunction' => [1],
        'createcollation' => [1],
        'fetchall' => [1]
    ];

    /**
     * @param Node\Expr\MethodCall $node
     * @return bool
     */
    public function checkNode(Node $node)
    {
        return parent::checkNode($node) && $this->isMethod($node, array_keys($this->sensitiveMethodName));
    }

    /**
     * @param Node\Expr\MethodCall $node
     */
    public function process(Node $node)
    {
        $fname = Code::getMethodName($node);
        if ($fname === 'fetchall') {
            $this->dealWithFetchAll($node);
            return;
        }

        foreach($this->sensitiveMethodName[$fname] as $pos) {
            if ($pos >= 0 && array_key_exists($pos, $node->args)) {
                $arg = $node->args[$pos];
            } elseif ($pos < 0 && array_key_exists(count($node->args) + $pos, $node->args)) {
                $arg = $node->args[ count($node->args) + $pos ];
            } else {
                continue ;
            }

            if (Code::hasVariable($arg->value) || Code::hasFunctionCall($arg->value)) {
                $this->message->danger($node, __CLASS__, "{$fname}方法第{$pos}个参数包含动态变量或函数，可能有远程代码执行的隐患");
            } elseif (!($arg->value instanceof Node\Expr\Closure)) {
                $this->message->warning($node, __CLASS__, "{$fname}方法第{$pos}个参数，请使用闭包函数");
            }
        }
    }

    /**
     * @param Node\Expr\MethodCall $node
     */
    protected function dealWithFetchAll(Node\Expr\MethodCall $node)
    {
        if (count($node->args) < 2) {
            return;
        }

        $fetchStyle = $node->args[0]->value;
        $fetchArgument = $node->args[1]->value;
        if ($fetchStyle instanceof Node\Expr\ClassConstFetch && $fetchStyle->class instanceof Node\Name && $fetchStyle->class->parts === ['PDO'] && $fetchStyle->name instanceof Node\Identifier && in_array($fetchStyle->name->name, ['FETCH_CLASS', 'FETCH_COLUMN'], true)) {
            return;
        } elseif ($fetchStyle instanceof Node\Scalar\LNumber && in_array($fetchStyle->value, [7, 8], true)) {
            return;
        } elseif (Code::hasVariable($fetchArgument) || Code::hasFunctionCall($fetchArgument)) {
            $this->message->danger($node, __CLASS__, "fetchAll方法第1个参数包含动态变量或函数，可能有远程代码执行的隐患");
            return;
        } elseif (!($fetchArgument instanceof Node\Expr\Closure)) {
            $this->message->warning($node, __CLASS__, "fetchAll方法第1个参数，请使用闭包函数");
            return;
        }
    }
}