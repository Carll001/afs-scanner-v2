<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DocumentBatchStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'excel_file' => ['required', 'file', 'mimes:xls,xlsx'],
            'template_file' => ['required', 'file', 'mimes:docx'],
            'sheet_index' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
