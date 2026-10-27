<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AdminSettingsController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function edit(): View
    {
        return view('admin.settings', [
            'config' => $this->dashboard->settings(),
            'users' => User::query()->orderBy('name')->get(),
            'roles' => User::ROLES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:150'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:4096'],
            'kg_per_mt' => ['required', 'numeric', 'gt:0'],
            'selling_price_per_mt' => ['required', 'numeric', 'min:0'],
            'cash_and_bank' => ['required', 'numeric', 'min:0'],
            'trade_receivables' => ['required', 'numeric', 'min:0'],
            'prepaid_expenses_and_taxes' => ['required', 'numeric', 'min:0'],
            'fixed_assets_heavy_equipment' => ['required', 'numeric', 'min:0'],
            'mining_properties_and_infrastructure' => ['required', 'numeric', 'min:0'],
            'accumulated_depreciation' => ['required', 'numeric', 'max:0'],
            'trade_payables_vendors' => ['required', 'numeric', 'min:0'],
            'accrued_operating_expenses' => ['required', 'numeric', 'min:0'],
            'short_term_lease_obligations' => ['required', 'numeric', 'min:0'],
            'long_term_lease_financing' => ['required', 'numeric', 'min:0'],
            'mine_closure_provision_aro' => ['required', 'numeric', 'min:0'],
            'share_capital' => ['required', 'numeric', 'min:0'],
            'retained_earnings' => ['required', 'numeric'],
            'rom_fallback_ratio' => ['required', 'numeric', 'between:0,1'],
            'hauling_volume_ratio' => ['required', 'numeric', 'between:0,2'],
            'washing_volume_ratio' => ['required', 'numeric', 'between:0,2'],
            'port_volume_ratio' => ['required', 'numeric', 'between:0,2'],
            'mining_direct_cost_per_mt' => ['required', 'numeric', 'min:0'],
            'rom_transfer_ratio' => ['required', 'numeric', 'between:0,1'],
            'haul_transfer_ratio' => ['required', 'numeric', 'between:0,1'],
            'washing_feed_ratio' => ['required', 'numeric', 'between:0,1'],
            'washing_yield_ratio' => ['required', 'numeric', 'between:0,1'],
            'washing_outflow_ratio' => ['required', 'numeric', 'between:0,1'],
            'port_direct_haul_ratio' => ['required', 'numeric', 'between:0,1'],
            'port_shipment_ratio' => ['required', 'numeric', 'between:0,1'],
        ]);
        $config = $this->dashboard->settings();
        $config['system']['companyName'] = $data['company_name'];
        if ($request->hasFile('logo')) {
            $logo = $request->file('logo');
            $filename = 'company-logo.'.$logo->getClientOriginalExtension();
            $logo->move(public_path('images'), $filename);
            $config['branding']['logoPath'] = 'images/'.$filename;
        }
        $config['dashboardSetup']['general']['kgPerMT'] = $data['kg_per_mt'];
        $config['dashboardSetup']['production']['romFallbackRatio'] = $data['rom_fallback_ratio'];
        $config['dashboardSetup']['costPerMT']['haulingVolumeRatio'] = $data['hauling_volume_ratio'];
        $config['dashboardSetup']['costPerMT']['washingVolumeRatio'] = $data['washing_volume_ratio'];
        $config['dashboardSetup']['costPerMT']['portVolumeRatio'] = $data['port_volume_ratio'];
        $config['dashboardSetup']['inventory']['miningDirectCostPerMT'] = $data['mining_direct_cost_per_mt'];
        $config['dashboardSetup']['inventory']['romTransferRatio'] = $data['rom_transfer_ratio'];
        $config['dashboardSetup']['inventory']['haulTransferRatio'] = $data['haul_transfer_ratio'];
        $config['dashboardSetup']['inventory']['washingFeedRatio'] = $data['washing_feed_ratio'];
        $config['dashboardSetup']['inventory']['washingYieldRatio'] = $data['washing_yield_ratio'];
        $config['dashboardSetup']['inventory']['washingOutflowRatio'] = $data['washing_outflow_ratio'];
        $config['dashboardSetup']['inventory']['portDirectHaulRatio'] = $data['port_direct_haul_ratio'];
        $config['dashboardSetup']['inventory']['portShipmentRatio'] = $data['port_shipment_ratio'];
        $config['dashboardSetup']['financial']['sellingPricePerMT'] = $data['selling_price_per_mt'];
        foreach (['cashAndBank' => 'cash_and_bank', 'tradeReceivables' => 'trade_receivables', 'prepaidExpensesAndTaxes' => 'prepaid_expenses_and_taxes', 'fixedAssetsHeavyEquipment' => 'fixed_assets_heavy_equipment', 'miningPropertiesAndInfrastructure' => 'mining_properties_and_infrastructure', 'accumulatedDepreciation' => 'accumulated_depreciation', 'tradePayablesVendors' => 'trade_payables_vendors', 'accruedOperatingExpenses' => 'accrued_operating_expenses', 'shortTermLeaseObligations' => 'short_term_lease_obligations', 'longTermLeaseFinancing' => 'long_term_lease_financing', 'mineClosureProvisionARO' => 'mine_closure_provision_aro', 'shareCapital' => 'share_capital', 'retainedEarnings' => 'retained_earnings'] as $key => $input) {
            $config['dashboardSetup']['financial'][$key] = $data[$input];
        }
        $this->dashboard->saveSettings($config);

        return back()->with('success', 'Branding dan parameter formula berhasil disimpan.');
    }

    public function storeUser(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'], 'role' => ['required', 'in:'.implode(',', array_keys(User::ROLES))],
        ]);
        User::create([...$data, 'password' => Hash::make($data['password']), 'is_active' => true]);

        return back()->with('success', 'User baru berhasil dibuat.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'in:'.implode(',', array_keys(User::ROLES))],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if ($user->is($request->user()) && $data['role'] !== 'administrator') {
            return back()->withErrors(['role' => 'Administrator tidak dapat menurunkan role dirinya sendiri.']);
        }
        $user->update(['role' => $data['role'], 'is_active' => $request->boolean('is_active')]);

        return back()->with('success', 'Akses user berhasil diperbarui.');
    }
}
