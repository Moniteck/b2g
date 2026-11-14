<?php

namespace App\Mcp\Support;

use RuntimeException;

/**
 * Error controlado de una herramienta MCP: su mensaje (en español) llega tal cual al cliente.
 */
class ToolError extends RuntimeException {}
