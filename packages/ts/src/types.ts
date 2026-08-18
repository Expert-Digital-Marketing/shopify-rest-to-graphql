/** The HTTP methods the Shopify REST Admin API uses. */
export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'DELETE';

/**
 * How completely a GraphQL operation replaces the REST call.
 *
 * `direct`  the operation does the same job with the same shape.
 * `partial` the operation does the job, but the call site has to change beyond swapping the transport.
 * `manual`  no single operation replaces it, so a person decides.
 */
export type MappingStatus = 'direct' | 'partial' | 'manual';

export interface RestEndpoint {
  readonly method: HttpMethod;
  /** Path relative to `/admin/api/{version}/`, with named segments in braces. */
  readonly path: string;
}

export interface GraphqlOperation {
  readonly operation: 'query' | 'mutation';
  readonly rootField: string;
  readonly document: string;
  readonly variables?: Readonly<Record<string, unknown>>;
  readonly accessScopes?: readonly string[];
}

export interface MappingRule {
  readonly id: string;
  readonly rest: RestEndpoint;
  readonly status: MappingStatus;
  readonly docs: string;
  readonly graphql?: GraphqlOperation;
  readonly notes?: readonly string[];
}

export interface MappingFile {
  readonly resource: string;
  readonly rules: readonly MappingRule[];
}

/** A REST call found in source, before it is matched against the mapping. */
export interface RestCallSite {
  readonly file: string;
  /** One based, so it lines up with what an editor shows. */
  readonly line: number;
  readonly column: number;
  readonly method: HttpMethod;
  /** Normalised path relative to `/admin/api/{version}/`, for example `products/123.json`. */
  readonly path: string;
  /** The API version in the URL, when the source spelled one out. */
  readonly apiVersion?: string;
  /** Which detector found it, so a false positive can be traced to its rule. */
  readonly detector: string;
  /** The source text that triggered the match, trimmed for reporting. */
  readonly evidence: string;
  /**
   * How sure the detector is that this really is a Shopify Admin REST call.
   * `certain` the path came from a literal we could read in full.
   * `likely`  the shape matched but part of the path was built at runtime.
   */
  readonly confidence: 'certain' | 'likely';
}

/** A call site paired with the rule that covers it, if any. */
export interface Finding {
  readonly call: RestCallSite;
  readonly rule?: MappingRule;
}
