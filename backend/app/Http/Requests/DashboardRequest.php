<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DashboardRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Ticket::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $fields = array_unique([
                ...array_keys($this->all()),
                ...array_keys($this->query->all()),
                ...array_keys($this->request->all()),
                ...array_keys($this->json()->all()),
            ]);

            foreach ($fields as $field) {
                $validator->errors()->add($field, 'Ce champ n’est pas autorisé.');
            }
        }];
    }
}
