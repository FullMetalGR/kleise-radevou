<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\ErrorEnvelope;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * An admin write's body, validated at the HTTP boundary, and refused the way the wire words a
 * refusal: a 400 envelope whose detail is the first field's own sentence, the same shape the
 * public API answers. The page shows that sentence as an error toast with its edits intact.
 *
 * The framework's own answer to a failed form request on a page is a redirect back with the
 * errors bag, which the admin does not read: a form here has one client gate, the name, and
 * every other sentence is the server's, delivered as the wire delivers one.
 *
 * Nothing here decides who may write. The route declares its permission, and the subscription
 * gate where it is a configuration write, and the middleware runs both before the body is read.
 */
abstract class AdminWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(ErrorEnvelope::for(new ValidationException($validator))->toResponse());
    }
}
