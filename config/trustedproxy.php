<?php

return [

    /*
     * Set trusted proxy IP addresses.
     *
     * Both IPv4 and IPv6 addresses are
     * supported, along with CIDR notation.
     *
     * Leave this unset unless the deployment explicitly identifies its proxy IPs or CIDRs.
     *
     */
    // 'proxies' => null, // [<ip addresses>,] or '<ip addresses>,'

    /*
     * To trust one or more specific proxies that connect
     * directly to your server, use an array or a string separated by comma of IP addresses:
     */
    // 'proxies' => ['192.168.1.1'],
    // 'proxies' => '192.168.1.1, 192.168.1.2',

    /*
     * Do not use a wildcard here. It trusts the peer that connects directly to the application.
     */
    'proxies' => env('TRUSTED_PROXIES'),

    /*
     * Which headers to use to detect proxy related data (For, Host, Proto, Port)
     *
     * Options include:
     *
     * - Illuminate\Http\Request::HEADER_X_FORWARDED_ALL (use all x-forwarded-* headers to establish trust)
     * - Illuminate\Http\Request::HEADER_FORWARDED (use the FORWARDED header to establish trust)
     * - Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB (If you are using AWS Elastic Load Balancer)
     *
     * - 'HEADER_X_FORWARDED_ALL' (use all x-forwarded-* headers to establish trust)
     * - 'HEADER_FORWARDED' (use the FORWARDED header to establish trust)
     * - 'HEADER_X_FORWARDED_AWS_ELB' (If you are using AWS Elastic Load Balancer)
     *
     * @link https://symfony.com/doc/current/deployment/proxies.html
     */
    'headers' => Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
        | Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
        | Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
        | Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
        | Illuminate\Http\Request::HEADER_X_FORWARDED_PREFIX
        | Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB,

];
