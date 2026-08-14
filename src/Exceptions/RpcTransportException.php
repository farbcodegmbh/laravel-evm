<?php

namespace Farbcode\LaravelEvm\Exceptions;

/**
 * No configured endpoint could be reached, or none produced a usable response.
 *
 * Unlike RpcErrorException this says nothing about the request itself, and is
 * the only case where trying another endpoint can help.
 */
class RpcTransportException extends RpcException {}
