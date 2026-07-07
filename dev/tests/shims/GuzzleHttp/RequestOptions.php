<?php
declare(strict_types=1);

namespace GuzzleHttp;

/**
 * Standalone-runner shim for GuzzleHttp\RequestOptions (Guzzle 7): the
 * request-option name constants, values identical to the real class. Loaded
 * via guarded require_once from WebhookExecuteTest only when real Guzzle is
 * absent — see the Client shim header.
 */
final class RequestOptions
{
    public const ALLOW_REDIRECTS = 'allow_redirects';
    public const AUTH = 'auth';
    public const BODY = 'body';
    public const CERT = 'cert';
    public const CONNECT_TIMEOUT = 'connect_timeout';
    public const COOKIES = 'cookies';
    public const CRYPTO_METHOD = 'crypto_method';
    public const DEBUG = 'debug';
    public const DECODE_CONTENT = 'decode_content';
    public const DELAY = 'delay';
    public const EXPECT = 'expect';
    public const FORCE_IP_RESOLVE = 'force_ip_resolve';
    public const FORM_PARAMS = 'form_params';
    public const HEADERS = 'headers';
    public const HTTP_ERRORS = 'http_errors';
    public const IDN_CONVERSION = 'idn_conversion';
    public const JSON = 'json';
    public const MULTIPART = 'multipart';
    public const ON_HEADERS = 'on_headers';
    public const ON_STATS = 'on_stats';
    public const PROGRESS = 'progress';
    public const PROXY = 'proxy';
    public const QUERY = 'query';
    public const READ_TIMEOUT = 'read_timeout';
    public const SINK = 'sink';
    public const SSL_KEY = 'ssl_key';
    public const STREAM = 'stream';
    public const SYNCHRONOUS = 'synchronous';
    public const TIMEOUT = 'timeout';
    public const VERIFY = 'verify';
    public const VERSION = 'version';
}
