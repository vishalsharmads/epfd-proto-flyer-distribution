<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\Abn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public const INDUSTRIES = ['Hospitality', 'Retail', 'Real estate', 'Trades & home services', 'Health & fitness', 'Education', 'Professional services', 'Automotive', 'Community & not-for-profit', 'Other'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'mobile' => preg_replace('/[^\d+]/', '', (string) $this->input('mobile')),
            'abn' => Abn::normalise($this->input('abn')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:120'],
            'contact_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            // AU mobile: 04xxxxxxxx or +614xxxxxxxx
            'mobile' => ['required', 'regex:/^(?:04\d{8}|\+614\d{8})$/'],
            'address' => ['required', 'string', 'max:255'],
            'address_gnaf_pid' => ['nullable', 'string', 'max:20'],
            'address_lng' => ['nullable', 'numeric', 'between:112,155'],
            'address_lat' => ['nullable', 'numeric', 'between:-45,-9'],
            'state' => ['nullable', 'in:ACT,NSW,NT,QLD,SA,TAS,VIC,WA,OT'],
            'industry' => ['required', 'in:'.implode(',', self::INDUSTRIES)],
            'abn' => ['nullable', new Abn],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'terms' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mobile.regex' => 'Enter an Australian mobile number, e.g. 0412 345 678.',
            'email.unique' => 'An account with this email already exists. Try signing in.',
            'terms.accepted' => 'Please accept the Terms & Conditions and Privacy Policy.',
            'address.required' => 'Start typing your business address and choose it from the list.',
        ];
    }
}
