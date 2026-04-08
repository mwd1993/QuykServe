# QuykServe

A lightweight, class-based PHP request and response library for building clean APIs without pulling in a full framework.

QuykServe gives you a clean, readable API for working with incoming requests, building consistent responses, managing headers and status codes, and normalizing errors in a simple, backend-friendly way.

---

## Features

| Feature | Description |
|---|---|
| Class-based API | Clean, reusable classes for request handling and responses |
| Lightweight | Small, focused API surface with minimal overhead |
| Framework-free | Works in plain PHP projects without needing a full stack framework |
| Request wrapper | Clean access to query params, body input, headers, cookies, files, and server data |
| Response helpers | Build JSON, text, HTML, redirects, downloads, and empty responses easily |
| Shared defaults | Configure headers, JSON encoding, hooks, and environment settings once |
| Normalized errors | Consistent error responses for API-friendly handling |
| JSON parsing | Automatically parses JSON request bodies when available |
| Header utilities | Simple helpers for reading and setting headers |
| Hooks | Lightweight request, response, and error hooks |
| Scalable structure | Clean internals that can grow with your project |

---

## Installation

For now, add the file directly to your project and include it manually.

```php
require_once __DIR__ . "/QuykServe.php";
```

---

## Quick Example

```php
<?php

use QuykServe\QuykServe;

$app = new QuykServe([
    "debug" => true
]);

$app->run(function ($request, $app) {
    return $app->success([
        "name" => "QuykServe",
        "version" => "1.0.0"
    ], "Library is working");
});
```

---

## Basic Usage

### Create an app instance

```php
use QuykServe\QuykServe;

$app = new QuykServe([
    "env" => "development",
    "debug" => true,
    "default_headers" => [
        "Content-Type" => "application/json; charset=utf-8"
    ]
]);
```

### Access the request

```php
$request = $app->request();

$method = $request->method();
$path = $request->path();
$url = $request->url();
```

### Read input

```php
$name = $request->input("name");
$email = $request->body("email");
$page = $request->query("page", 1);
```

### Read headers

```php
$authHeader = $request->header("authorization");
$token = $request->bearerToken();
```

### Read files and cookies

```php
$file = $request->file("avatar");
$session = $request->cookie("session_id");
```

---

## Response Helpers

QuykServe includes convenient helpers for common API responses.

### Success response

```php
return $app->success([
    "id" => 42,
    "name" => "John Doe"
], "User loaded successfully");
```

### Created response

```php
return $app->created([
    "id" => 43,
    "name" => "Jane Doe"
], "User created");
```

### Failure response

```php
return $app->fail("Something went wrong", 400, [
    "field" => "email"
]);
```

### Validation response

```php
return $app->validation([
    "email" => ["Email is required"],
    "password" => ["Password must be at least 8 characters"]
]);
```

### Not found response

```php
return $app->notFound("User not found");
```

### Empty response

```php
return $app->noContent();
```

---

## Standard JSON Response Shape

QuykServe is designed to encourage consistent API responses.

A typical JSON response looks like this:

```json
{
  "success": true,
  "message": "User loaded successfully",
  "data": {
    "id": 42,
    "name": "John Doe"
  }
}
```

Error responses follow the same general shape:

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["Email is required"]
  }
}
```

This makes it especially nice to pair with QuykFetch on the frontend.

---

## Request Object

The `Request` class gives you a clean wrapper around PHP's native superglobals.

| Method | Description |
|---|---|
| `method()` | Get the HTTP method |
| `isMethod()` | Check if the request matches a method |
| `url()` | Get the full request URL |
| `path()` | Get the current path |
| `query()` | Read query string values |
| `body()` | Read parsed request body values |
| `input()` | Read merged query + body values |
| `json()` | Read parsed JSON body values |
| `header()` | Read headers |
| `bearerToken()` | Extract a Bearer token |
| `cookie()` | Read cookies |
| `file()` | Read uploaded files |
| `ip()` | Get the client IP |
| `userAgent()` | Get the user agent |
| `isAjax()` | Detect AJAX requests |
| `expectsJson()` | Check if the client expects JSON |
| `all()` | Get all merged input |
| `only()` | Return only specific fields |
| `except()` | Exclude specific fields |

### Example

```php
$data = $request->only(["name", "email"]);

if (!$request->filled("email")) {
    return $app->validation([
        "email" => ["Email is required"]
    ]);
}
```

---

## Response Object

The `Response` class lets you build and send different response types.

| Method | Description |
|---|---|
| `json()` | Send a JSON response |
| `text()` | Send plain text |
| `html()` | Send HTML |
| `redirect()` | Redirect to another URL |
| `download()` | Send a file download response |
| `empty()` | Send an empty response |
| `status()` | Set the status code |
| `header()` | Set one header |
| `headers()` | Set multiple headers |
| `body()` | Set the raw body |
| `send()` | Send the final response |

### Example

```php
return $app->response()
    ->status(200)
    ->header("X-App", "QuykServe")
    ->json([
        "success" => true,
        "message" => "Custom response"
    ]);
```

---

## Error Handling

QuykServe includes a `QuykServeError` class for normalized API-safe exceptions.

### Throwing a custom error

```php
use QuykServe\QuykServeError;

throw QuykServeError::notFound("Record not found");
```

### Built-in error helpers

| Helper | Status |
|---|---|
| `badRequest()` | 400 |
| `unauthorized()` | 401 |
| `forbidden()` | 403 |
| `notFound()` | 404 |
| `validation()` | 422 |
| `tooManyRequests()` | 429 |
| `serverError()` | 500 |

### Handling exceptions

```php
$app->run(function ($request, $app) {
    if (!$request->has("id")) {
        throw QuykServeError::badRequest("Missing required id");
    }

    return $app->success(["id" => $request->input("id")]);
});
```

In debug mode, QuykServe can include extra exception details to help during development.

---

## Hooks

QuykServe supports lightweight hooks for request, response, and error flow.

```php
$app = new QuykServe([
    "hooks" => [
        "beforeRequest" => function ($request, $app) {
            return $request;
        },
        "beforeResponse" => function ($response, $app) {
            return $response->header("X-Powered-By", "QuykServe");
        },
        "onError" => function ($error, $app) {
            return null;
        }
    ]
]);
```

These are useful for logging, auth checks, custom headers, and response shaping.

---

## Running a Handler

The `run()` method gives you a simple entry point for endpoint logic.

```php
$app->run(function ($request, $app) {
    if ($request->isMethod("GET")) {
        return $app->success([
            "users" => []
        ]);
    }

    return $app->badRequest("Unsupported method");
});
```

QuykServe will automatically convert returned arrays and objects into success JSON responses.

---

## Why Use QuykServe?

QuykServe is a good fit when you want:

- a cleaner alternative to raw PHP superglobals and manual header handling
- a lightweight API helper without a full framework
- reusable request and response classes
- consistent JSON output across endpoints
- normalized backend errors
- a clean pairing with QuykFetch on the frontend

---

## Pairing with QuykFetch

QuykServe works especially well with QuykFetch.

- QuykServe produces consistent API responses
- QuykFetch consumes them with normalized parsing and errors
- together they give you a lightweight client + server stack

That makes them a clean pair for small apps, dashboards, tools, and custom APIs.

---

## License

MIT
