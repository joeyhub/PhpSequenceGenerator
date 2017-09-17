<?php

namespace SimpleParser
{
    trait ExceptionWithStateTrait
    {
        private $state;

        private function setState($state)
        {
            $this->state = $state;
        }

        public function getState()
        {
            return $this->state;
        }
    }

    class StateNotFoundException extends \Exception
    {
        use ExceptionWithStateTrait;

        public function __construct(string $state)
        {
            parent::__construct("Cound not find state: {$state}");
            $this->setState($state);
        }
    }

    class NewStateNotFoundException extends \Exception
    {
        use ExceptionWithStateTrait;
        private $character;

        public function __construct(string $state, string $character)
        {
            parent::__construct("Cound not find new state from: {$state} => {$character}");
            $this->setState($state);
            $this->character = $character;
        }

        public function getCharacter()
        {
            return $this->character;
        }
    }

    class EofStateNotFoundException extends \Exception
    {
        use ExceptionWithStateTrait;

        public function __construct(string $state)
        {
            parent::__construct("Did not find EOF state, instead found: {$state}");
            $this->setState($state);
        }
    }

    function debug_states_to_handler_tree($states) {
        $handlers = ['old' => [], 'new' => [], 'two' => []];

        foreach($states as $old_state => $changes) {
            $handlers['old'][] = $old_state;
            $handlers['two'][$old_state] = [];

            foreach(array_keys($changes) as $new_state) {
                $handlers['new'][] = $new_state;
                $handlers['two'][$old_state][] = $new_state;
            }
        }

        return $handlers;
    }

    function parse($sequence, $states, $handler) {
        $find_new_state = function($old_state, $character) use($states) {
            if(!isset($states[$old_state]))
                throw new StateNotFoundException($old_state);

            foreach($states[$old_state] as $new_state => $characters)
                if($characters === $character || $characters === null)
                    return $new_state;
                elseif($characters !== '' && $character !== '' && strpos($characters, $character) !== false)
                    return $new_state;

            throw new NewStateNotFoundException($old_state, $character);
        };

        $change_state = function($old_state, $character) use($find_new_state, $handler) {
            $new_state = $find_new_state($old_state, $character);

            if($handler !== null)
                $handler($old_state, $new_state, $character);

            return $new_state;
        };

        $state = $change_state('BOF', '');
        $length = strlen($sequence);

        for($i = 0; $i < $length; $i++)
            $state = $change_state($state, $sequence[$i]);

        $state = $change_state($state, '');

        if($state !== 'EOF')
            throw new EofStateNotFoundException($state);
    }
}

namespace Sequence
{
    interface GeneratorInterface
    {
        public function getLength();
        public function getLengths();
        public function getAt($i);
    }

    abstract class BaseGenerator implements GeneratorInterface
    {
        protected $length;

        public static function couple($arr)
        {
            if(count($arr) === 1)
                $arr[] = $arr[0];

             return $arr;
        }

        public static function positions($lengths, $i)
        {
            $positions = [];

            foreach(array_reverse($lengths) as $length) {
                $positions[] = $i % $length;
                $i = floor($i / $length);
            }

            return array_reverse($positions);
        }

        public static function factory($config)
        {
            $type = [
                'range' => 'Range',
                'repeat' => 'Repeat',
                'scope' => 'Scope',
                'literal' => 'Literal',
                'or' => 'Or',
                'list' => 'List'
            ][array_shift($config)];
            $type = "Sequence\\{$type}Generator";
            return new $type($config);
        }

        public function getLength()
        {
            return $this->length;
        }

        public function getLengths()
        {
            return [static::class => $this->length];
        }
    }

    class RangeGenerator extends BaseGenerator
    {
        private $range;

        public function __construct($config)
        {
            $this->range = range($config[0], $config[1]);
            $this->length = count($this->range);
        }

        public function getAt($i)
        {
            return $this->range[$i];
        }
    }

    class ListGenerator extends BaseGenerator
   {
        private $list;

        public function __construct($config)
        {
            $this->list = $config[0];
            $this->length = strlen($this->list);
        }

        public function getAt($i)
        {
            return $this->list[$i];
        }
    }

    class RepeatGenerator extends BaseGenerator
    {
        private $min;
        private $max;
        private $generator;

        public function __construct($config)
        {
            $range = self::couple($config[0]);
            $this->min = $range[0];
            $this->max = $range[1];
            $this->generator = self::factory($config[1]);

            $length = $this->generator->getLength();
            $this->length = 0;

            for($i = $this->min; $i <= $this->max; $i++)
                $this->length += pow($length, $i);
        }

        public function getAt($i)
        {
            $length = $this->generator->getLength();
            $total = 0;

            for($h = $this->min; $h <= $this->max; $h++) {
                $size = pow($length, $h);

                if($i < $size + $total) {
                    $lengths = array_fill(0, $h, $length);
                    $positions = self::positions($lengths, $i - $total);
                    $str = '';

                    foreach($positions as $position)
                        $str .= $this->generator->getAt($position);

                    return $str;
                }

                $total += $size;
            }

            throw new Exception('Out of range.');
        }

        public function getLengths()
        {
            $length = $this->generator->getLengths();
            $l = $this->generator->getLength();
            $lengths = [];

            for($i = $this->min; $i <= $this->max; $i++)
                $lengths[] = [array_fill(0, $i, $length), static::class => pow($l, $i)];

            return [$lengths, static::class => $this->length];
        }
    }

    class ScopeGenerator extends BaseGenerator
    {
        private $generators;

        public function __construct($config)
        {
            if(count($config) === 0)
                throw new Exception('No generators.');

            $this->generators = [];

            foreach($config as $v)
                $this->generators[] = self::factory($v);

            $this->length = 1;

            foreach($this->generators as $generator)
                $this->length *= $generator->getLength();
        }

        public function getAt($i)
        {
            if(count($this->generators) === 0)
                throw new Exception('No generators.');

            $positions = self::positions(array_map(function($i) {
                return $i->getLength();
            }, $this->generators), $i);

            $str = '';

            foreach($positions as $i => $position)
                $str .= $this->generators[$i]->getAt($position);

            return $str;
        }

        public function getLengths()
        {
            $lengths = [];

            foreach($this->generators as $generator)
                $lengths[] = $generator->getLengths();

            return [$lengths, static::class => $this->length];
        }
    }

    class OrGenerator extends BaseGenerator
    {
        private $generators;

        public function __construct($config)
        {
            if(count($config) === 0)
                throw new Exception('No generators.');

            $this->generators = [];

            foreach($config as $v)
                $this->generators[] = self::factory($v);

            $this->length = 0;

            foreach($this->generators as $generator)
                $this->length += $generator->getLength();
        }

        public function getAt($i)
        {
            foreach($this->generators as $generator) {
                $next_length = $generator->getLength();

                if($i < $next_length)
                    return $generator->getAt($i);

                $i -= $next_length;
            }

            throw new Exception('Out of range.');
        }

        public function getLengths()
        {
            $lengths = [];

            foreach($this->generators as $generator)
                $lengths[] = $generator->getLengths();

            return [$lengths, static::class => $this->length];
        }
    }

    class LiteralGenerator extends BaseGenerator
    {
        private $literal;

        public function __construct($config)
        {
            $this->literal = $config[0];
            $this->length = 1;
        }

        public function getAt($i)
        {
            if($i !== 0)
                throw new Exception('Out of range.');

            return $this->literal;
        }
    }
}

namespace
{
    error_reporting(E_ALL);
    $states = [
        'ERR' => ['EOF' => '', 'ERR' => null],
        'BOF' => ['regex_start' => ''],
        'regex_start' => ['ERR' => '{?)|', 'regex_start' => '(', 'regex_escape' => '\\', 'list_start' => '[', 'EOF' => '', 'regex_next' => null],
        'regex_next' => ['regex_next_repeat' => '?', 'regex_repeat_from_start' => '{', 'regex_start' => '(', 'regex_next_regex' => ')', 'regex_escape' => '\\', 'list_start' => '[', 'regex_next_or' => '|', 'EOF' => '', 'regex_next' => null],
        'regex_next_regex' => ['regex_next_repeat' => '?', 'regex_repeat_from_start' => '{', 'regex_start' => '(', 'regex_next_regex' => ')', 'regex_escape' => '\\', 'list_start' => '[', 'regex_next_or' => '|', 'EOF' => '', 'regex_next' => null],
        'regex_next_list' => ['regex_next_repeat' => '?', 'regex_repeat_from_start' => '{', 'regex_start' => '(', 'regex_next_regex' => ')', 'regex_escape' => '\\', 'list_start' => '[', 'regex_next_or' => '|', 'EOF' => '', 'regex_next' => null],
        'regex_next_repeat' => ['ERR' => '{?', 'regex_start' => '(', 'regex_next_regex' => ')', 'regex_escape' => '\\', 'list_start' => '[', 'regex_next_or' => '|', 'EOF' => '', 'regex_next' => null],
        'regex_escape' => ['regex_next' => '\\lLd()[*+?{', 'ERR' => null],
        'list_start' => ['ERR' => ']', 'regex_escape' => '\\', 'list_next' => null],
        'list_escape' => ['list_next' => '\\lLd]-', 'ERR' => null],
        'list_next' => ['list_range_next' => '-', 'regex_escape' => '\\', 'regex_next_list' => ']', 'list_next' => null],
        'list_range_next' => ['list_next_range' => null],
        'list_next_range' => ['regex_escape' => '\\', 'regex_next_list' => ']', 'list_next' => null],
        'regex_next_or' => ['ERR' => '{?)|', 'regex_start' => '(', 'regex_escape' => '\\', 'list_start' => '[', 'regex_next' => null],
        'regex_repeat_from_start' => ['regex_repeat_from_next' => '0123456789', 'regex_repeat_to_start' => ','],
        'regex_repeat_from_next' => ['regex_repeat_from_next' => '0123456789', 'regex_repeat_to_start' => ',', 'regex_next_repeat' => '}'],
        'regex_repeat_to_start' => ['regex_repeat_to_next' => '123456789'],
        'regex_repeat_to_next' => ['regex_repeat_to_next' => '0123456789', 'regex_next_repeat' => '}']
    ];
    $data = (object)[];
    $handler = call_user_func(function(&$data) {
        $data->stack = [];
        $data->characters = '';
        $data->node = $data->or = $data->repeat = null;

        $lists = [
            'd' => implode(range('0', '9')),
            'l' => implode(range('a', 'z')),
            'L' => implode(range('A', 'Z'))
        ];

        $functions = [
            'store_characters' => function() use($data) {
                if($data->characters !== '') {
                    $data->node[] = ['literal', $data->characters];
                    $data->characters = '';
                }
            },
            'store_last_character' => function() use($data) {
                if($data->characters !== '') {
                    if(strlen($data->characters) > 1)
                        $data->node[] = ['literal' => substr($data->characters, 0, -1)];

                    $data->node[] = ['literal', substr($data->characters, -1)];
                    $data->characters = '';
                }
            },
            'wrap_tail' => function(array $wrap) use($data) {
                $wrap[] = array_pop($data->node);
                $data->node[] = $wrap;
            }
        ];

        return function($old_state, $new_state, $character) use($data, $functions, $lists) {
            if($new_state === 'ERR')
                throw new Exception('Syntax error while parsing regex.');

            echo "{$old_state} {$new_state} {$character}\n";

            if($new_state === 'EOF') {
                if(count($data->stack))
                    throw new Exception('Scopes not closed all the way.');

                $functions['store_characters']();

                if($data->or !== null) {
                    $data->or[] = $data->node;
                    $data->node = $data->or;
                    $data->or = null;
                }

                return;
            }

            if($new_state === 'regex_start') {
                if($data->node !== null) {
                    $functions['store_characters']();
                    $data->stack[] = [$data->node, $data->or];
                }

                $data->node = ['scope'];
                $data->or = null;
                return;
            }

            if($new_state === 'regex_next_regex') {
                $functions['store_characters']();

                if(!count($data->stack))
                    throw new Exception('Scope stack exhausted.');

                [$node, $or] = array_pop($data->stack);

                if($data->or !== null) {
                    $data->or[] = $data->node;
                    $data->node = $data->or;
                }

                $node[] = $data->node;
                [$data->node, $data->or] = [$node, $or];
                return;
            }

            if($new_state === 'regex_repeat_from_start') {
                $functions['store_last_character']();
                $data->repeat = [];
                return;
            }

            if($new_state === 'regex_repeat_to_start') {
                $data->repeat[] = (int)$data->characters;
                $data->characters = '';
                return;
            }

            if($new_state === 'regex_next_repeat') {
                if($data->repeat === null) {
                    $functions['store_last_character']();
                    $functions['wrap_tail'](['repeat', [0, 1]]);
                    return;
                }

                while(count($data->repeat) < 2)
                    $data->repeat[] = (int)$data->characters;

                $data->characters = '';
                $functions['wrap_tail'](['repeat', $data->repeat]);
                $data->repeat = null;
                return;
            }

            if($new_state === 'list_start') {
                $functions['store_characters']();
                return;
            }

            if($new_state === 'regex_next_list') {
                $data->node[] = ['list', $data->characters];
                $data->characters = '';
                return;
            }

            if($old_state === 'list_next_range')
                $data->characters = substr($data->characters, 0, -2) . implode(range(substr($data->characters, -2, 1), substr($data->characters, -1)));

            if($new_state === 'regex_next_or') {
                $functions['store_characters']();

                if($data->or === null)
                    $data->or = ['or'];

                $data->or[] = $data->node;
                $data->node = ['scope'];
                return;
            }

            if(in_array($new_state, ['list_escape', 'regex_escape', 'list_range_next'], true))
                return;

            if($old_state === 'list_escape') {
                if(isset($lists[$character])) {
                    $data->characters .= $lists[$character];
                    return;
                }
            }

            if($old_state === 'regex_escape') {
                if(isset($lists[$character])) {
                    $functions['store_characters']();
                    $data->node[] = ['list', $lists[$character]];
                    return;
                }
            }

            $data->characters .= $character;
        };
    }, $data);

    $print_tree = function($node, $depth) use(&$print_tree) {
        $t = str_repeat(' ', $depth);

        foreach($node as $k => $v)
        {
            if(is_array($v)) {
                //echo "{$t}{$k} =>\n";
                $print_tree($v, $depth + 1);
                continue;
            }

            echo "{$t}{$k} => {$v}\n";
        }
    };

    $literals_to_literal = function($literals) {
        $str = '';

        foreach($literals as $literal)
            $str .= $literal[1];

        return ['literal', $str];
    };

    $compact = function($node, $parent) use(&$compact, $literals_to_literal) {
        if($node[0] === 'scope') {
            foreach($node as $k => $v) {
                if($k === 0)
                    continue;

                $node[$k] = $compact($v, $node);
            }

            $scope = [];
            $literals = [];

            foreach($node as $k => $v) {
                if($v[0] === 'literal')
                    $literals[] = $v;
                else {
                    if(count($literals))
                    {
                        $scope[] = $literals_to_literal($literals);
                        $literals = [];
                    }

                    $scope[] = $v;
                }
            }

            if(count($literals))
                $scope[] = $literals_to_literal($literals);

            if($parent !== null && in_array($parent[0], ['scope', 'or'], true) && count($scope) === 2)
                return $scope[1];

            return $scope;
        }

        if($node[0] === 'or') {
            $or = ['or'];

            foreach($node as $k => $v) {
                if($k === 0)
                    continue;

                $v = $compact($v, $node);

                if($v[0] === 'or')
                    $or = array_merge($or, array_slice($v, 1));
                else
                    $or[] = $v;
            }

            return $or;
        }

        return $node;
    };

    print_r(\SimpleParser\debug_states_to_handler_tree($states));
    $regex = $argv[1];
    SimpleParser\parse($regex, $states, $handler);
    print_r($data);

    $tree = $data->node;
    echo "AST:\n";
    $print_tree($tree, 0);
    echo "AST (compact):\n";
    $tree = $compact($tree, null);
    $print_tree($tree, 0);
    $g = Sequence\BaseGenerator::factory($tree);
    echo "Lengths:\n";
    $print_tree($g->getLengths(), 0);
    echo "\n";
    echo "Expression: {$regex}\n";
    echo "Combinations: ".$g->getLength()."\n";
    echo "Position 0: ".$g->getAt(0)."\n";
    echo "Position 1: ".$g->getAt(1)."\n";
    echo "Position 2: ".$g->getAt(2)."\n";
    echo "Position Middle -1: ".$g->getAt(($g->getLength() >> 1) - 1)."\n";
    echo "Position Middle: ".$g->getAt($g->getLength() >> 1)."\n";
    echo "Position Middle + 1: ".$g->getAt(($g->getLength() >> 1) + 1)."\n";
    echo "Position Last: ".$g->getAt($g->getLength() -1)."\n";
    echo "\n\n";
}
