<?php

declare(strict_types=1);

namespace EdmUk\ShopifyRestToGraphql;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * Find Shopify REST Admin API calls in one PHP file.
 *
 * Detection is syntactic. It reads what the source says, not what it would do
 * at runtime, so a path built from variables is reported with `likely`
 * confidence and a wildcard where the value could not be read.
 */
final class Detector extends NodeVisitorAbstract
{
    private const HTTP_METHOD_NAMES = [
        'get' => 'GET',
        'post' => 'POST',
        'put' => 'PUT',
        'delete' => 'DELETE',
        'del' => 'DELETE',
    ];

    /**
     * Methods that take a verb and a path as their first two arguments, in
     * either order. `rest` and `doRequest` are what the Laravel Shopify
     * packages use; `request` is Guzzle and most wrappers.
     */
    private const REQUEST_LIKE_NAMES = ['request', 'rest', 'dorequest', 'call'];

    /**
     * Array keys that name the HTTP verb beside a URL.
     *
     * `httpMethod` matters: Guzzle service descriptions use it, and without it
     * every entry in a description file is reported as a GET.
     */
    private const METHOD_KEYS = ['method', 'httpmethod', 'http_method', 'verb'];

    /** @var list<RestCallSite> */
    private array $found = [];

    /**
     * String valued local variables seen so far in the current function.
     *
     * @var array<string, string>
     */
    private array $localStrings = [];

    /**
     * Nodes already accounted for by an earlier, better informed detector.
     * Keyed by object id, because a multi line call puts the URL on a
     * different line from the method that names the verb.
     *
     * @var array<int, true>
     */
    private array $claimedNodes = [];

    private function __construct(private readonly string $file)
    {
    }

    /**
     * @return list<RestCallSite>
     */
    public static function detect(string $file, string $source): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $ast = $parser->parse($source);
        } catch (Error) {
            // One unparseable file must not stop the scan. The caller counts
            // what was skipped and says so.
            throw new UnparseableFile($file);
        }

        if ($ast === null) {
            return [];
        }

        $visitor = new self($file);
        $traverser = new NodeTraverser();
        // The method sometimes sits in a sibling array entry rather than in the
        // node itself, so the visitor needs to be able to walk back up.
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $found = $visitor->found;
        usort($found, static fn (RestCallSite $a, RestCallSite $b): int => $a->line <=> $b->line);

        return $found;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\FunctionLike) {
            // Locals do not survive across functions, and pretending they
            // do would resolve a name to a value from somewhere else.
            $this->localStrings = [];
        }

        if ($node instanceof Node\Expr\Assign
            && $node->var instanceof Node\Expr\Variable
            && is_string($node->var->name)
        ) {
            [$value] = $this->readString($node->expr);
            if ($value !== null) {
                $this->localStrings[$node->var->name] = $value;
            }
        }

        if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) {
            $this->fromClientCall($node);
        }

        if ($node instanceof Node\Scalar\String_) {
            $this->fromUrlLiteral($node);
        }

        if ($node instanceof Node\Scalar\InterpolatedString) {
            $this->fromInterpolatedUrl($node);
        }

        return null;
    }

    /**
     * `$client->get('products.json')`, `$client->request('PUT', $url)` and the
     * Laravel `Http::put($url)` form all put the method in the call itself.
     */
    private function fromClientCall(Node\Expr\MethodCall|Node\Expr\StaticCall $node): void
    {
        if (! $node->name instanceof Node\Identifier) {
            return;
        }

        $name = strtolower($node->name->toString());
        $arguments = $node->getArgs();

        $method = null;
        $pathArgument = null;

        if (in_array($name, self::REQUEST_LIKE_NAMES, true) && isset($arguments[0], $arguments[1])) {
            // Guzzle takes (verb, path). Several Shopify wrappers take
            // (path, verb). Read whichever argument is the verb.
            [$method, $pathArgument] = self::readRequestArguments(
                $arguments[0]->value,
                $arguments[1]->value,
            );
        } elseif (isset(self::HTTP_METHOD_NAMES[$name]) && isset($arguments[0])) {
            $method = self::HTTP_METHOD_NAMES[$name];
            $pathArgument = $arguments[0]->value;
        }

        if ($method === null || $pathArgument === null) {
            return;
        }

        [$raw, $confidence] = $this->readString($pathArgument);
        if ($raw === null || ! self::looksLikeAdminPath($raw)) {
            return;
        }

        // The URL node itself must not be reported again by the literal pass,
        // which knows the path but not the verb.
        $this->claimedNodes[spl_object_id($pathArgument)] = true;

        $this->record($node->getStartLine(), $method, $raw, 'client-method-call', $confidence);
    }

    /**
     * Work out which of the two arguments is the verb and which is the path.
     *
     * @return array{0: string|null, 1: Node|null}
     */
    private static function readRequestArguments(Node $first, Node $second): array
    {
        $firstVerb = self::asVerb($first);
        if ($firstVerb !== null) {
            return [$firstVerb, $second];
        }

        $secondVerb = self::asVerb($second);
        if ($secondVerb !== null) {
            return [$secondVerb, $first];
        }

        return [null, null];
    }

    /**
     * Read an HTTP verb from a string, or from the enum style constants that
     * Laravel Shopify packages use: `ApiMethod::GET()` and `Method::GET`.
     */
    private static function asVerb(Node $node): ?string
    {
        $candidate = null;

        if ($node instanceof Node\Scalar\String_) {
            $candidate = $node->value;
        } elseif ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier) {
            $candidate = $node->name->toString();
        } elseif ($node instanceof Node\Expr\ClassConstFetch && $node->name instanceof Node\Identifier) {
            $candidate = $node->name->toString();
        }

        if ($candidate === null) {
            return null;
        }

        $upper = strtoupper($candidate);

        return in_array($upper, ['GET', 'POST', 'PUT', 'DELETE'], true) ? $upper : null;
    }

    /**
     * `$thing->get('shop')` is far more often a session or config read than a
     * Shopify call, so a bare word is never enough. Require either the Admin
     * prefix or the `.json` suffix that every REST resource path carries.
     *
     * Real codebases proved this necessary: without it, `$request->get('state')`
     * in an OAuth helper was reported as a REST call to migrate.
     */
    private static function looksLikeAdminPath(string $raw): bool
    {
        return str_contains($raw, '/admin/') || str_ends_with($raw, '.json');
    }

    /** A URL string anywhere that names `/admin/api/{version}/`. */
    private function fromUrlLiteral(Node\Scalar\String_ $node): void
    {
        if (isset($this->claimedNodes[spl_object_id($node)])) {
            return;
        }
        if (! str_contains($node->value, '/admin/')) {
            return;
        }

        $method = $this->methodFromContext($node) ?? 'GET';
        $this->record($node->getStartLine(), $method, $node->value, 'admin-url-literal', 'certain');
    }

    /** `"https://shop.myshopify.com/admin/api/2024-10/variants/{$id}.json"`. */
    private function fromInterpolatedUrl(Node\Scalar\InterpolatedString $node): void
    {
        if (isset($this->claimedNodes[spl_object_id($node)])) {
            return;
        }

        [$raw, $confidence] = $this->readString($node);
        if ($raw === null || ! str_contains($raw, '/admin/')) {
            return;
        }

        $method = $this->methodFromContext($node) ?? 'GET';
        $this->record($node->getStartLine(), $method, $raw, 'admin-url-literal', $confidence);
    }

    /**
     * Read a string node, substituting a wildcard for anything interpolated.
     *
     * @return array{0: string|null, 1: 'certain'|'likely'}
     */
    private function readString(Node $node): array
    {
        // Only these three node kinds are a path at the top level. Anything
        // else is a value, and treating a value as a path would report every
        // variable in the file.
        if ($node instanceof Node\Scalar\String_
            || $node instanceof Node\Scalar\InterpolatedString
            || $node instanceof Node\Expr\BinaryOp\Concat
        ) {
            return $this->readPart($node);
        }

        // A variable is only a path if we watched it being assigned a string
        // earlier in the same function. Unknown names stay unknown.
        if ($node instanceof Node\Expr\Variable
            && is_string($node->name)
            && isset($this->localStrings[$node->name])
        ) {
            return [$this->localStrings[$node->name], 'likely'];
        }

        return [null, 'certain'];
    }

    /**
     * Read one part of a string expression, recursing through concatenation.
     *
     * `'orders/' . $id . '/fulfillments.json'` is the common PHP idiom, and
     * the literal halves carry the resource, so it is worth reading. The parts
     * that cannot be read become a wildcard.
     *
     * @return array{0: string|null, 1: 'certain'|'likely'}
     */
    private function readPart(Node $node): array
    {
        if ($node instanceof Node\Scalar\String_) {
            return [$node->value, 'certain'];
        }

        // `$endpoint = 'products.json';` on one line and the call on the next
        // is the dominant idiom in PHP Shopify wrappers. Without this the
        // scanner misses most of a real codebase.
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            $known = $this->localStrings[$node->name] ?? null;
            if ($known !== null) {
                // Only ever `likely`: a later branch could reassign it, and
                // this pass does not follow control flow.
                return [$known, 'likely'];
            }
        }

        if ($node instanceof Node\Scalar\InterpolatedString) {
            $text = '';
            foreach ($node->parts as $part) {
                $text .= $part instanceof Node\InterpolatedStringPart ? $part->value : '{...}';
            }

            return [$text, 'likely'];
        }

        if ($node instanceof Node\Expr\BinaryOp\Concat) {
            [$left, $leftConfidence] = $this->readPart($node->left);
            [$right, $rightConfidence] = $this->readPart($node->right);
            if ($left === null || $right === null) {
                return [null, 'certain'];
            }

            $confidence = $leftConfidence === 'likely' || $rightConfidence === 'likely' ? 'likely' : 'certain';

            return [$left . $right, $confidence];
        }

        return ['{...}', 'likely'];
    }

    /**
     * Look for a method in the same call, either as a `'method' => 'POST'`
     * array entry or in the name of the method being called.
     */
    private function methodFromContext(Node $node): ?string
    {
        $parent = $node->getAttribute('parent');
        if ($parent instanceof Node\Expr\ArrayItem) {
            $array = $parent->getAttribute('parent');
            if ($array instanceof Node\Expr\Array_) {
                foreach ($array->items as $item) {
                    if ($item?->key instanceof Node\Scalar\String_
                        && in_array(strtolower($item->key->value), self::METHOD_KEYS, true)
                    ) {
                        $verb = self::asVerb($item->value);
                        if ($verb !== null) {
                            return $verb;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function record(int $line, string $method, string $raw, string $detector, string $confidence): void
    {
        $normalised = PathNormaliser::normalise($raw);
        if ($normalised === null || PathNormaliser::isGraphqlEndpoint($normalised['path'])) {
            return;
        }

        /** @var 'certain'|'likely' $confidence */
        $this->found[] = new RestCallSite(
            file: $this->file,
            line: $line,
            method: $method,
            path: $normalised['path'],
            apiVersion: $normalised['apiVersion'],
            detector: $detector,
            evidence: self::trimEvidence($raw),
            confidence: $confidence,
        );
    }

    private static function trimEvidence(string $text): string
    {
        $single = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($single) > 120 ? mb_substr($single, 0, 117) . '...' : $single;
    }
}
