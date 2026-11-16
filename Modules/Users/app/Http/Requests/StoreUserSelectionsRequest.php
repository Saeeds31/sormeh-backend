<?php

namespace Modules\Specifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserSelectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'selections' => ['required', 'array', 'min:1'],
            'selections.*.specification_id' => ['required', 'integer', 'exists:specifications,id'],
            'selections.*.specification_value_id' => ['required', 'integer', 'exists:specification_values,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'selections.required' => 'حداقل یک انتخاب الزامی است.',
            'selections.*.specification_id.exists' => 'مشخصه انتخاب‌شده معتبر نیست.',
            'selections.*.specification_value_id.exists' => 'مقدار انتخاب‌شده معتبر نیست.',
        ];
    }
}
