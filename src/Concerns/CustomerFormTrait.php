<?php

namespace CristhCasco\SgDnit\Concerns;

use CristhCasco\SgDnit\Services\DnitRucService;
use Illuminate\Support\Facades\DB;

/**
 * Formulario de cliente compartido entre la pantalla de Clientes y el alta rápida del POS:
 * campos, validaciones, normalización y autocompletado con la base local de la DNIT.
 */
trait CustomerFormTrait
{
    public $selected_id = 0;
    public $person = 'Elegir';
    public $name = '';
    public $last_name = '';
    public $email = '';
    public $phone = '';
    public $address = '';
    public $birthday = '';
    public $credit_limit = null;
    public $company = '';
    public $autoDetected = false;

    // CI / RUC (un solo campo: para persona física el CI es el mismo número)
    public $document_number = '';
    public $dv = '';

    // Resultado de la consulta a la DNIT
    public $dnitStatus;   // ACTIVO, CANCELADO, SUSPENSION TEMPORAL, ...
    public $dnitMessage;

    // Legal invoicing
    public $document_type_id;
    public $tax_condition_id;

    protected function rules()
    {
        $rules = [
            'person' => 'required|in:FISICA,JURIDICA',
            'document_number' => [
                'required',
                'regex:/^\d{3,9}$/',
                function ($attribute, $value, $fail) {
                    if ($duplicate = $this->findDuplicate($value)) {
                        $fail('Ya existe un cliente con este documento: ' . $duplicate->display_name . '.');
                    }
                },
            ],
            'dv' => [
                $this->person === 'JURIDICA' ? 'required' : 'nullable',
                'regex:/^\d$/',
                function ($attribute, $value, $fail) {
                    $expected = DnitRucService::calcularDv((string) $this->document_number);
                    if ($expected !== null && (int) $value !== $expected) {
                        $fail("El DV no corresponde a ese RUC (debería ser {$expected}).");
                    }
                },
            ],
            'phone' => ['nullable', 'regex:/^09\d{8}$/'],
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|max:500',
            'birthday' => 'nullable|date|before:today|after:1900-01-01',
            'credit_limit' => 'nullable|numeric|min:0',
        ];

        // Solo la pantalla de Clientes permite subir imagen
        if (property_exists($this, 'image')) {
            $rules['image'] = 'nullable|image|max:2048';
        }

        if ($this->person === 'FISICA') {
            $rules['name'] = 'required|min:2|max:255';
            $rules['last_name'] = 'required|min:2|max:255';
        } elseif ($this->person === 'JURIDICA') {
            $rules['company'] = 'required|min:3|max:255';
        }

        return $rules;
    }

    protected function messages()
    {
        return [
            'person.required' => 'Elegí el tipo de persona',
            'person.in' => 'Elegí el tipo de persona',
            'document_number.required' => 'El CI / RUC es requerido',
            'document_number.regex' => 'El CI / RUC debe tener solo números (entre 3 y 9 dígitos)',
            'dv.required' => 'El DV es requerido para personas jurídicas',
            'dv.regex' => 'El DV debe ser un solo dígito',
            'name.required' => 'El nombre es requerido',
            'name.min' => 'El nombre debe tener al menos 2 caracteres',
            'last_name.required' => 'El apellido es requerido',
            'last_name.min' => 'El apellido debe tener al menos 2 caracteres',
            'company.required' => 'La razón social es requerida',
            'company.min' => 'La razón social debe tener al menos 3 caracteres',
            'phone.regex' => 'Ingresá un celular paraguayo válido, ej: 0981123456',
            'email.email' => 'El correo no es válido',
            'birthday.before' => 'La fecha de nacimiento debe ser anterior a hoy',
            'birthday.after' => 'La fecha de nacimiento no es válida',
            'credit_limit.numeric' => 'El límite de crédito debe ser un número',
            'credit_limit.min' => 'El límite de crédito no puede ser negativo',
            'image.image' => 'El archivo debe ser una imagen',
            'image.max' => 'La imagen no puede superar los 2 MB',
        ];
    }

    /**
     * Limpia el formato de lo que se escribe (puntos del CI, guiones y espacios del celular).
     */
    protected function normalizeInputs()
    {
        // "1.234.567-8" -> número 1234567 y DV 8
        $doc = trim((string) $this->document_number);
        if (preg_match('/^([\d.\s]+)-(\d)$/', $doc, $m)) {
            $doc = $m[1];
            $this->dv = $m[2];
        }
        $this->document_number = preg_replace('/[.\s]/', '', $doc);

        $this->dv = trim((string) $this->dv);
        $this->phone = preg_replace('/[\s\-().]/', '', (string) $this->phone);
        if (strpos($this->phone, '+595') === 0) {
            $this->phone = '0' . substr($this->phone, 4);
        }

        $this->name = trim((string) $this->name);
        $this->last_name = trim((string) $this->last_name);
        $this->company = trim((string) $this->company);
        $this->email = trim((string) $this->email);
        $this->address = trim((string) $this->address);
    }

    protected function findDuplicate($documentNumber)
    {
        $customerModel = config('sg-dnit.customer_model', \App\Models\Customer::class);

        return $customerModel::where(function ($q) use ($documentNumber) {
                $q->where('document_number', $documentNumber)
                  ->orWhere('ci', $documentNumber);
            })
            ->when($this->selected_id, function ($q) {
                $q->where('id', '!=', $this->selected_id);
            })
            ->first();
    }

    /**
     * Datos a guardar. Con DV el cliente es contribuyente (RUC); sin DV, se factura con CI.
     */
    protected function payload()
    {
        $isFisica = $this->person === 'FISICA';
        $hasRuc = $this->dv !== '';
        $contribuyente = $this->catalogId('tax_conditions', ['IVA', 'C'], ['IVA Responsable', 'Contribuyente']);
        $consumidorFinal = $this->catalogId('tax_conditions', ['CF', 'NC'], ['Consumidor Final', 'No Contribuyente']);
        $keepTaxCondition = $this->tax_condition_id && (int) $this->tax_condition_id !== (int) $consumidorFinal;

        return [
            'person' => $this->person,
            'name' => $isFisica ? $this->name : null,
            'last_name' => $isFisica ? $this->last_name : null,
            'ci' => $isFisica ? (int) $this->document_number : null,
            'company' => $isFisica ? null : $this->company,

            'document_number' => $this->document_number,
            'dv' => $hasRuc ? $this->dv : null,

            'email' => $this->email ?: null,
            'phone' => $this->phone ?: null,
            'address' => $this->address ?: null,
            'birthday' => $this->birthday ?: null,
            'credit_limit' => $this->credit_limit !== '' ? $this->credit_limit : null,

            'document_type_id' => $hasRuc
                ? $this->catalogId('document_types', ['RUC'], ['RUC', 'Registro Único del Contribuyente'])
                : $this->catalogId('document_types', ['CI'], ['CI', 'Cédula de Identidad']),
            'tax_condition_id' => $hasRuc
                ? ($keepTaxCondition ? $this->tax_condition_id : $contribuyente)
                : $consumidorFinal,
        ];
    }

    /**
     * Id de un registro de catálogo. Las bases de los sistemas SG no son todas iguales:
     * las creadas por migración tienen columna "code" (CI, RUC / C, NC) y otras solo "name"
     * (RUC, CI) o códigos IVA / CF. Se busca primero por código y después por nombre.
     */
    protected function catalogId(string $table, array $codes, array $names)
    {
        static $cache = [];
        $key = $table . '|' . implode(',', $codes) . '|' . implode(',', $names);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $id = null;
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'code')) {
            foreach ($codes as $code) {
                if ($id = DB::table($table)->where('code', $code)->value('id')) {
                    break;
                }
            }
        }
        if (!$id) {
            foreach ($names as $name) {
                if ($id = DB::table($table)->where('name', $name)->value('id')) {
                    break;
                }
            }
        }

        return $cache[$key] = $id;
    }

    /**
     * Vacía los campos del formulario de cliente.
     */
    protected function resetCustomerForm()
    {
        $this->person = 'Elegir';
        $this->name = '';
        $this->last_name = '';
        $this->email = '';
        $this->phone = '';
        $this->company = '';
        $this->document_number = '';
        $this->dv = '';
        $this->address = '';
        $this->birthday = '';
        $this->credit_limit = null;
        $this->document_type_id = null;
        $this->tax_condition_id = null;
        $this->dnitStatus = null;
        $this->dnitMessage = null;
        $this->selected_id = 0;

        // Muy importante para evitar que el select PERSONA quede bloqueado
        $this->autoDetected = false;

        $this->resetValidation();
    }

    /**
     * Al cargar el CI / RUC busca el contribuyente en la copia local del listado
     * de la DNIT y completa DV, nombre/apellido o razón social.
     */
    public function updatedDocumentNumber()
    {
        $this->resetValidation(['document_number', 'dv']);
        $this->dnitStatus = null;
        $this->dnitMessage = null;

        $this->normalizeInputs();
        $doc = $this->document_number;

        if (!preg_match('/^\d{3,9}$/', $doc)) {
            return;
        }

        if ($duplicate = $this->findDuplicate($doc)) {
            $this->addError('document_number', 'Ya existe un cliente con este documento: ' . $duplicate->display_name . '.');
            return;
        }

        $dnit = app(DnitRucService::class);
        $data = $dnit->buscar($doc);

        if (!$data) {
            $this->autoDetected = false;
            $this->dv = '';
            $this->dnitMessage = $dnit->hayDatos()
                ? 'No figura como contribuyente en la DNIT: se registrará con CI (sin DV). Cargá los datos a mano.'
                : 'La base de la DNIT no está cargada. Ejecutá "php artisan dnit:importar".';
            return;
        }

        $this->dv = $data['dv'];
        $this->dnitStatus = $data['estado'];
        $this->person = $data['tipo'];
        $this->autoDetected = true;

        if ($data['tipo'] === 'JURIDICA') {
            $this->company = $data['razon_social'];
            $this->name = '';
            $this->last_name = '';
        } else {
            $this->name = $data['nombre'] ?? '';
            $this->last_name = $data['apellido'] ?? '';
            $this->company = '';
        }

        $this->dnitMessage = $data['estado'] === 'ACTIVO'
            ? 'Datos obtenidos de la DNIT.'
            : "Atención: el RUC figura como {$data['estado']} en la DNIT.";
    }

    /**
     * Permite corregir a mano si la detección automática no es correcta.
     */
    public function unlockPerson()
    {
        $this->autoDetected = false;
    }
}
