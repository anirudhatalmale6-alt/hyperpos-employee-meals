<?php

namespace EmployeeMeals\Http\Controllers;

use EmployeeMeals\Models\Department;
use EmployeeMeals\Models\Employee;
use EmployeeMeals\Models\MealCompany;
use EmployeeMeals\Models\SubsidyPlan;
use EmployeeMeals\Models\WorkShift;
use EmployeeMeals\Support\EntitlementEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The employee 360 record.
 *
 * One employee = one record, holding the details, the photo, the access card
 * number and the link to the Hyper customer. Fingerprints hang off the same
 * record and are enrolled on their own screen.
 */
class EmployeeController extends \App\Http\Controllers\Controller
{
    public function __construct(private readonly EntitlementEngine $entitlements) {}

    public function index(Request $request): View
    {
        $this->allow('employee-meals.employees.view');

        $employees = Employee::query()
            ->with(['company', 'department', 'shift', 'subsidyPlan'])
            ->search($request->query('q'))
            ->when($request->filled('company'), fn ($q) => $q->where('em_company_id', $request->integer('company')))
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('employee_no')
            ->paginate(25)
            ->withQueryString();

        return view('employee-meals::employees.index', [
            'employees' => $employees,
            'companies' => MealCompany::query()->orderBy('name')->get(),
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'companyId' => $request->query('company'),
        ]);
    }

    public function create(): View
    {
        $this->allow('employee-meals.employees.manage');

        return view('employee-meals::employees.form', $this->formData(new Employee([
            'is_active' => true,
            'meal_entitled' => true,
        ])));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow('employee-meals.employees.manage');

        $employee = new Employee();
        $data = $this->validated($request, $employee);
        $data['photo_path'] = $this->storePhoto($request, null);

        $employee->fill($data)->save();

        return redirect()
            ->route('employee-meals.employees.index')
            ->with('status', __('employee-meals::meals.employees.created', ['name' => $employee->full_name]));
    }

    public function edit(Employee $employee): View
    {
        $this->allow('employee-meals.employees.manage');

        return view('employee-meals::employees.form', $this->formData($employee));
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $this->allow('employee-meals.employees.manage');

        $data = $this->validated($request, $employee);
        if ($photo = $this->storePhoto($request, $employee->photo_path)) {
            $data['photo_path'] = $photo;
        }

        $employee->fill($data)->save();

        return redirect()
            ->route('employee-meals.employees.index')
            ->with('status', __('employee-meals::meals.employees.saved', ['name' => $employee->full_name]));
    }

    /**
     * Soft delete only. A hard delete would take the ledger history with it,
     * and that history is what the employer is billed on.
     */
    public function destroy(Employee $employee): RedirectResponse
    {
        $this->allow('employee-meals.employees.manage');

        $employee->delete();

        return redirect()
            ->route('employee-meals.employees.index')
            ->with('status', __('employee-meals::meals.employees.removed', ['name' => $employee->full_name]));
    }

    /**
     * Scan a card, get back the person and whether today's meal is still
     * available. This is the same answer the till will need.
     */
    public function lookupByCard(Request $request): JsonResponse
    {
        $this->allow('employee-meals.identify');

        $card = trim((string) $request->query('card'));
        if ($card === '') {
            return response()->json(['found' => false, 'reason' => 'empty'], 422);
        }

        $employee = Employee::query()
            ->with(['company', 'department', 'subsidyPlan'])
            ->where('card_number', $card)
            ->first();

        if ($employee === null) {
            return response()->json(['found' => false, 'reason' => 'unknown_card']);
        }

        $status = $this->entitlements->status($employee);

        return response()->json([
            'found' => true,
            'employee' => [
                'id' => $employee->id,
                'employee_no' => $employee->employee_no,
                'name' => $employee->full_name,
                'department' => $employee->department?->name,
                'company' => $employee->company?->name,
                'photo_url' => $employee->photo_path ? Storage::url($employee->photo_path) : null,
                'is_active' => $employee->is_active,
            ],
            'meal' => [
                'entitled' => $status['entitled'],
                'status' => $status['status'],
                'value' => $status['value'],
                'collected_at' => $status['collected_at']?->format('H:i'),
                'reason' => $status['reason'],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Employee $employee): array
    {
        return $request->validate([
            'em_company_id' => ['required', Rule::exists('em_companies', 'id')],
            'em_department_id' => ['nullable', Rule::exists('em_departments', 'id')],
            'em_shift_id' => ['nullable', Rule::exists('em_shifts', 'id')],
            'em_subsidy_plan_id' => ['nullable', Rule::exists('em_subsidy_plans', 'id')],

            // The link to Hyper's own customer. Nullable, and validated against
            // their table by name rather than by a foreign key, so the plugin
            // never constrains a core table.
            'customer_id' => ['nullable', Rule::exists('customers', 'id')],

            'employee_no' => [
                'required', 'string', 'max:40',
                Rule::unique('em_employees', 'employee_no')
                    ->where('em_company_id', $request->integer('em_company_id'))
                    ->whereNull('deleted_at')
                    ->ignore($employee->id),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],

            // A card must resolve to exactly one person.
            'card_number' => [
                'nullable', 'string', 'max:64',
                Rule::unique('em_employees', 'card_number')
                    ->whereNull('deleted_at')
                    ->ignore($employee->id),
            ],
            'photo' => ['nullable', 'image', 'max:4096'],
            // A shot taken with the webcam arrives as a data URI rather than a
            // file upload. Capped well above a 640x480 JPEG so a large frame
            // is refused rather than silently truncated.
            'photo_capture' => ['nullable', 'string', 'max:4000000'],
            'is_active' => ['nullable', 'boolean'],
            'meal_entitled' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]) + [
            'is_active' => $request->boolean('is_active'),
            'meal_entitled' => $request->boolean('meal_entitled'),
        ];
    }

    /**
     * A photograph arrives one of two ways - a chosen file, or a still taken
     * with the webcam on this PC. Both end up in the same place.
     *
     * ⚠️ A capture that cannot be stored throws rather than returning null.
     * The first version swallowed the failure and saved the employee with no
     * photograph and no message, which is indistinguishable from the feature
     * being broken - and that is exactly how the client experienced it.
     *
     * @throws ValidationException
     */
    private function storePhoto(Request $request, ?string $existing): ?string
    {
        if ($request->hasFile('photo')) {
            return $request->file('photo')->store('employee-meals/photos', 'public');
        }

        $capture = (string) $request->input('photo_capture', '');
        if ($capture !== '') {
            return $this->storeCapture($capture);
        }

        return $existing;
    }

    /**
     * Decode a `data:image/jpeg;base64,…` still from the camera.
     *
     * The bytes are checked to be a real image before anything is written - a
     * data URI is just a string from the browser, so the declared type proves
     * nothing.
     *
     * @throws ValidationException
     */
    private function storeCapture(string $dataUri): string
    {
        $fail = fn (string $why) => throw ValidationException::withMessages([
            'photo' => __('employee-meals::meals.employees.photo.failed', ['reason' => $why]),
        ]);

        if (! preg_match('#^data:image/(jpeg|jpg|png);base64,#i', $dataUri)) {
            $fail(__('employee-meals::meals.employees.photo.reason_not_a_data_uri'));
        }

        $binary = base64_decode(substr($dataUri, strpos($dataUri, ',') + 1), true);
        if ($binary === false || $binary === '') {
            $fail(__('employee-meals::meals.employees.photo.reason_undecodable'));
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            $fail(__('employee-meals::meals.employees.photo.reason_not_an_image'));
        }

        $extension = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
        $path = 'employee-meals/photos/'.Str::uuid()->toString().'.'.$extension;

        if (! Storage::disk('public')->put($path, $binary)) {
            $fail(__('employee-meals::meals.employees.photo.reason_not_writable'));
        }

        return $path;
    }

    /** @return array<string, mixed> */
    private function formData(Employee $employee): array
    {
        $companies = MealCompany::query()->orderBy('name')->get();

        return [
            'employee' => $employee,
            'companies' => $companies,
            'departments' => Department::query()->orderBy('name')->get(),
            'shifts' => WorkShift::query()->orderBy('name')->get(),
            'plans' => SubsidyPlan::query()->orderBy('name')->get(),
            // Only a short list - the field is a search box, not a dropdown of
            // every customer in the shop.
            'linkedCustomer' => $employee->customer_id
                ? DB::table('customers')->where('id', $employee->customer_id)->first()
                : null,
        ];
    }

    /** Plugin permissions are plain keys; a super admin bypasses them. */
    private function allow(string $permission): void
    {
        $user = auth()->user();

        if ($user === null || (! $user->is_super_admin && ! $user->hasPermission($permission))) {
            abort(403);
        }
    }
}
