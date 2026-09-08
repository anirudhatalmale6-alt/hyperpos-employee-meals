@php
    $isEdit = $employee->exists;
    $action = $isEdit
        ? route('employee-meals.employees.update', $employee)
        : route('employee-meals.employees.store');
    $heading = $isEdit
        ? $employee->full_name
        : __('employee-meals::meals.employees.new');
@endphp

<x-admin-layout
    active="employee-meals-employees"
    :title="$heading"
    :crumbs="[
        ['label' => __('employee-meals::meals.employees.crumb_parent')],
        ['label' => __('employee-meals::meals.employees.title'), 'href' => route('employee-meals.employees.index')],
        ['label' => $heading],
    ]">

    <div class="page-wide">
        <form method="POST" action="{{ $action }}" enctype="multipart/form-data" novalidate>
            @csrf
            @if ($isEdit) @method('PATCH') @endif

            <div class="page-header mb-6">
                <div class="flex items-start gap-3">
                    <a href="{{ route('employee-meals.employees.index') }}"
                       class="pos-btn pos-btn-sm pos-btn-ghost"
                       aria-label="{{ __('employee-meals::meals.employees.title') }}">
                        <x-icon name="back" class="w-4 h-4" />
                    </a>
                    <div>
                        <h1 class="page-title">{{ $heading }}</h1>
                        <p class="page-sub">{{ __('employee-meals::meals.employees.sub') }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('employee-meals.employees.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost">
                        {{ __('employee-meals::meals.employees.actions.discard') }}
                    </a>
                    <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                        <x-icon name="check" class="w-4 h-4" />
                        {{ $isEdit
                            ? __('employee-meals::meals.employees.actions.save')
                            : __('employee-meals::meals.employees.actions.create') }}
                    </button>
                </div>
            </div>

            @if ($errors->any())
                <div class="card mb-4">
                    <div class="card-body">
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li class="field-error">{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">
                <div class="space-y-5">

                    {{-- ── Identity ──────────────────────────────────── --}}
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">{{ __('employee-meals::meals.employees.cards.identity') }}</div>
                        </div>
                        <div class="card-body space-y-4">
                            <label class="field">
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.employee_no') }}</span>
                                <input type="text" name="employee_no" class="pos-input" required
                                       value="{{ old('employee_no', $employee->employee_no) }}"
                                       placeholder="CIP00125">
                            </label>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <label class="field">
                                    <span class="field-label">{{ __('employee-meals::meals.employees.fields.first_name') }}</span>
                                    <input type="text" name="first_name" class="pos-input" required
                                           value="{{ old('first_name', $employee->first_name) }}">
                                </label>
                                <label class="field">
                                    <span class="field-label">{{ __('employee-meals::meals.employees.fields.last_name') }}</span>
                                    <input type="text" name="last_name" class="pos-input" required
                                           value="{{ old('last_name', $employee->last_name) }}">
                                </label>
                            </div>

                            <label class="field">
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.photo') }}</span>
                                <input type="file" name="photo" accept="image/*" class="pos-input">
                                @if ($employee->photo_path)
                                    <span class="field-help">{{ $employee->photo_path }}</span>
                                @endif
                            </label>

                            <label class="field">
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.notes') }}</span>
                                <textarea name="notes" rows="3" class="pos-input">{{ old('notes', $employee->notes) }}</textarea>
                            </label>
                        </div>
                    </div>

                    {{-- ── Credentials ───────────────────────────────── --}}
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">{{ __('employee-meals::meals.employees.cards.credentials') }}</div>
                        </div>
                        <div class="card-body space-y-4">
                            <label class="field">
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.card_number') }}</span>
                                {{-- autocomplete off: a card reader types into this box like a
                                     keyboard, and a browser suggestion list would swallow it --}}
                                <input type="text" name="card_number" class="pos-input"
                                       autocomplete="off"
                                       value="{{ old('card_number', $employee->card_number) }}"
                                       placeholder="12345678">
                                <span class="field-help">{{ __('employee-meals::meals.employees.help.card_number') }}</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="space-y-5">

                    {{-- ── Company and placement ─────────────────────── --}}
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">{{ __('employee-meals::meals.employees.cards.placement') }}</div>
                        </div>
                        <div class="card-body space-y-4">
                            <label class="field">
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.company') }}</span>
                                <select name="em_company_id" class="pos-input" required>
                                    @foreach ($companies as $company)
                                        <option value="{{ $company->id }}"
                                            @selected((string) old('em_company_id', $employee->em_company_id) === (string) $company->id)>
                                            {{ $company->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="field">
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.department') }}</span>
                                <select name="em_department_id" class="pos-input">
                                    <option value="">—</option>
                                    @foreach ($departments as $department)
                                        <option value="{{ $department->id }}"
                                            @selected((string) old('em_department_id', $employee->em_department_id) === (string) $department->id)>
                                            {{ $department->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="field">
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.shift') }}</span>
                                <select name="em_shift_id" class="pos-input">
                                    <option value="">—</option>
                                    @foreach ($shifts as $shift)
                                        <option value="{{ $shift->id }}"
                                            @selected((string) old('em_shift_id', $employee->em_shift_id) === (string) $shift->id)>
                                            {{ $shift->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <span class="field-help">{{ __('employee-meals::meals.employees.help.shift') }}</span>
                            </label>
                        </div>
                    </div>

                    {{-- ── Meal entitlement ──────────────────────────── --}}
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">{{ __('employee-meals::meals.employees.cards.entitlement') }}</div>
                        </div>
                        <div class="card-body space-y-4">
                            <label class="field">
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.plan') }}</span>
                                <select name="em_subsidy_plan_id" class="pos-input">
                                    <option value="">—</option>
                                    @foreach ($plans as $plan)
                                        <option value="{{ $plan->id }}"
                                            @selected((string) old('em_subsidy_plan_id', $employee->em_subsidy_plan_id) === (string) $plan->id)>
                                            {{ $plan->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <span class="field-help">{{ __('employee-meals::meals.employees.help.plan') }}</span>
                            </label>

                            {{-- The link to Hyper's own customer record. Held by id and
                                 never written to, so nothing on the customer changes. --}}
                            <label class="field">
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.customer') }}</span>
                                <input type="number" name="customer_id" class="pos-input" min="1"
                                       value="{{ old('customer_id', $employee->customer_id) }}"
                                       placeholder="{{ __('employee-meals::meals.employees.status.not_linked') }}">
                                <span class="field-help">
                                    {{ __('employee-meals::meals.employees.help.customer') }}
                                    @if ($linkedCustomer)
                                        — {{ $linkedCustomer->name }}
                                    @endif
                                </span>
                            </label>

                            <label class="field field-toggle">
                                <input type="checkbox" name="meal_entitled" value="1"
                                       @checked(old('meal_entitled', $employee->meal_entitled))>
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.meal_entitled') }}</span>
                                <span class="field-help">{{ __('employee-meals::meals.employees.help.meal_entitled') }}</span>
                            </label>

                            <label class="field field-toggle">
                                <input type="checkbox" name="is_active" value="1"
                                       @checked(old('is_active', $employee->is_active))>
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.is_active') }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</x-admin-layout>
