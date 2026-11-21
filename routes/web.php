<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\CashController;
use App\Http\Controllers\CashflowController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\CommerceController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\FiscalController;
use App\Http\Controllers\FiscalEmissionController;
use App\Http\Controllers\NfeEmissionController;
use App\Http\Controllers\FiscalProfileController;
use App\Http\Controllers\SimulationFiscalController;

use App\Http\Controllers\SellerController;
use App\Http\Controllers\DeskController;
use App\Http\Controllers\LicenseSelfServiceController;
use App\Http\Middleware\EnforceContractAccess;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\TenantAuthenticated;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));
Route::prefix('app/{company}')->where(['company' => '[a-z0-9-]+'])->middleware(ResolveTenant::class)->group(function () {
    Route::view('ativar', 'auth.activate')->name('company.activate');
    Route::post('ativar', [\App\Http\Controllers\CompanyActivationController::class, 'activate'])->middleware('throttle:6,1');
    Route::get('convite-implantacao', [\App\Http\Controllers\InstallerInvitationController::class, 'form'])->name('installer.invite');
    Route::post('convite-implantacao', [\App\Http\Controllers\InstallerInvitationController::class, 'accept'])->middleware('throttle:6,1')->name('installer.invite.accept');
    Route::controller(\App\Http\Controllers\TenantPasswordResetController::class)->group(function () {
        Route::get('recuperar-senha', 'forgot')->name('password.request');
        Route::post('recuperar-senha', 'send')->middleware('throttle:10,1,password-request:')->name('password.email');
        Route::get('redefinir-senha', 'form')->name('password.reset');
        Route::post('redefinir-senha', 'update')->middleware('throttle:12,1,password-reset:')->name('password.update');
    });
    Route::get('business/logo',[BusinessController::class,'logo'])->name('business.logo');
    Route::get('login', [AuthController::class, 'form'])->name('login');
    Route::post('login', [AuthController::class, 'login']);
    Route::middleware([TenantAuthenticated::class, EnforceContractAccess::class])->group(function () {
        Route::get('licenca/ajuda', \App\Http\Controllers\LicenseHelpController::class)->name('license.help');
        Route::prefix('licenca')->name('license.')->group(function () {
            Route::get('/', [LicenseSelfServiceController::class, 'index'])->name('index');
            Route::get('parcelas/{installment}', [LicenseSelfServiceController::class, 'show'])->whereNumber('installment')->name('installments.show');
            Route::post('parcelas/{installment}/pix', [LicenseSelfServiceController::class, 'issue'])->whereNumber('installment')->middleware('throttle:10,1')->name('installments.issue');
            Route::post('parcelas/{installment}/consultar', [LicenseSelfServiceController::class, 'refresh'])->whereNumber('installment')->middleware('throttle:20,1')->name('installments.refresh');
        });
        Route::get('implantacao/situacao', [\App\Http\Controllers\InstallerInvitationController::class, 'home'])->name('installer.home');
        Route::get('account/password', [AccountController::class, 'password'])->name('account.password');
        Route::post('account/password', [AccountController::class, 'changePassword'])->name('account.password.save');
        Route::post('users/{id}', [AccountController::class, 'updateUser'])->whereNumber('id')->name('user.update');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', [CommerceController::class, 'dashboard'])->name('dashboard');
        require __DIR__.'/restaurant.php';
        require __DIR__.'/supermarket.php';
        Route::get('prices',[PricingController::class,'index'])->name('prices');
        Route::post('prices/refresh',[PricingController::class,'refresh'])->name('price.refresh');
        Route::post('prices/{id}/apply',[PricingController::class,'apply'])->whereNumber('id')->name('price.apply');
        Route::get('cash', [CashController::class, 'index'])->name('cash');
        Route::post('cash/open', [CashController::class, 'open'])->name('cash.open');
        Route::post('cash/movement', [CashController::class, 'movement'])->name('cash.movement');
        Route::post('cash/close', [CashController::class, 'close'])->name('cash.close');
        Route::get('cashflow', [CashflowController::class, 'index'])->name('cashflow');
        Route::post('business/cnpj', [BusinessController::class, 'lookupRegistry'])
            ->middleware('throttle:10,1')->name('business.cnpj');
        Route::get('business', [BusinessController::class, 'form'])->name('business');
        Route::get('setup', [\App\Http\Controllers\CompanySetupController::class,'index'])->name('setup');
        Route::post('setup', [\App\Http\Controllers\CompanySetupController::class,'save'])->name('setup.save');
        Route::post('setup/company', [\App\Http\Controllers\CompanySetupController::class,'company'])->name('setup.company.save');
        Route::post('setup/fiscal/continue', [\App\Http\Controllers\CompanySetupController::class,'fiscalContinue'])->name('setup.fiscal.continue');
        Route::post('setup/team', [\App\Http\Controllers\CompanySetupController::class,'team'])->name('setup.team.save');
        Route::post('setup/complete', [\App\Http\Controllers\CompanySetupController::class,'complete'])->name('setup.complete');
        Route::post('business', [BusinessController::class, 'save']);
        Route::get('fiscal', [FiscalController::class, 'index'])->name('fiscal');
        Route::get('fiscal/company', [BusinessController::class, 'fiscal'])->name('fiscal.company');
        Route::middleware(\App\Http\Middleware\ProductInvoicesEnabled::class)->group(function () {
            Route::post('business/fiscal/nfe', [NfeEmissionController::class, 'configure'])->name('nfe.configure');
            Route::post('business/fiscal/nfe/validate', [NfeEmissionController::class, 'validateLocal'])->name('nfe.validate');
            Route::get('fiscal/nfe/customers/{id}', [NfeEmissionController::class, 'customer'])->whereNumber('id')->name('nfe.customer');
            Route::post('fiscal/nfe/customers/{id}', [NfeEmissionController::class, 'saveCustomer'])->whereNumber('id')->name('nfe.customer.save');
            Route::post('sales/{id}/fiscal/nfe/emit', [NfeEmissionController::class, 'emit'])->whereNumber('id')->name('nfe.emit');
            Route::get('fiscal/nfe/documents/{id}', [NfeEmissionController::class, 'show'])->whereNumber('id')->name('nfe.document');
            Route::post('fiscal/nfe/documents/{id}/consult', [NfeEmissionController::class, 'consult'])->whereNumber('id')->name('nfe.consult');
            Route::post('fiscal/nfe/documents/{id}/resend', [NfeEmissionController::class, 'resend'])->whereNumber('id')->name('nfe.resend');
            Route::post('fiscal/nfe/documents/{id}/cancel', [NfeEmissionController::class, 'cancel'])->whereNumber('id')->name('nfe.cancel');
            Route::get('fiscal/nfe/documents/{id}/files/{kind}', [NfeEmissionController::class, 'download'])->whereNumber('id')->name('nfe.download');

            Route::get('fiscal/profiles', [FiscalProfileController::class, 'index'])->name('fiscal.profiles.index');
            Route::get('fiscal/profiles/create', [FiscalProfileController::class, 'create'])->name('fiscal.profiles.create');
            Route::post('fiscal/profiles', [FiscalProfileController::class, 'store'])->name('fiscal.profiles.store');
            Route::get('fiscal/profiles/{id}/edit', [FiscalProfileController::class, 'edit'])->whereNumber('id')->name('fiscal.profiles.edit');
            Route::post('fiscal/profiles/{id}', [FiscalProfileController::class, 'update'])->whereNumber('id')->name('fiscal.profiles.update');
            Route::get('fiscal/profiles/{id}/bulk', [FiscalProfileController::class, 'bulk'])->whereNumber('id')->name('fiscal.profiles.bulk');
            Route::get('fiscal/profiles/{id}/preview', [FiscalProfileController::class, 'bulk'])->whereNumber('id')->name('fiscal.profiles.preview.back');
            Route::post('fiscal/profiles/{id}/preview', [FiscalProfileController::class, 'preview'])->whereNumber('id')->name('fiscal.profiles.preview');
            Route::post('fiscal/profiles/{id}/apply', [FiscalProfileController::class, 'apply'])->whereNumber('id')->name('fiscal.profiles.apply');
            Route::post('fiscal/products/{id}/profile', [FiscalProfileController::class, 'bindProduct'])->whereNumber('id')->name('fiscal.products.profile');
            Route::post('fiscal/groups/{id}/profile', [FiscalProfileController::class, 'assignGroup'])->whereNumber('id')->name('fiscal.groups.profile');
            Route::post('fiscal/products/{id}/individual/clear', [FiscalProfileController::class, 'clearIndividual'])->whereNumber('id')->name('fiscal.products.individual.clear');

            Route::get('fiscal/simulations', [SimulationFiscalController::class, 'index'])->name('fiscal.simulations.index');
            Route::post('sales/{id}/fiscal/simulate', [SimulationFiscalController::class, 'create'])->whereNumber('id')->name('fiscal.simulations.create');
            Route::get('fiscal/simulations/{id}', [SimulationFiscalController::class, 'show'])->whereNumber('id')->name('fiscal.simulations.show');
            Route::post('fiscal/simulations/{id}/consult', [SimulationFiscalController::class, 'consult'])->whereNumber('id')->name('fiscal.simulations.consult');
            Route::post('fiscal/simulations/{id}/cancel', [SimulationFiscalController::class, 'cancel'])->whereNumber('id')->name('fiscal.simulations.cancel');
            Route::get('fiscal/simulations/{id}/download', [SimulationFiscalController::class, 'download'])->whereNumber('id')->name('fiscal.simulations.download');
            Route::get('fiscal/simulations/{id}/receipt', [SimulationFiscalController::class, 'receipt'])->whereNumber('id')->name('fiscal.simulations.receipt');

            Route::post('business/fiscal', [FiscalEmissionController::class,'configure'])->name('business.fiscal');
            Route::post('business/fiscal/validate', [FiscalEmissionController::class,'validateLocal'])->name('business.fiscal.validate');
            Route::post('fiscal/voids', [FiscalEmissionController::class,'invalidate'])->name('fiscal.invalidate');
            Route::get('fiscal/voids/{id}/xml', [FiscalEmissionController::class,'voidXml'])->whereNumber('id')->name('fiscal.void.xml');
            Route::post('fiscal/products/{id}/unit', [FiscalEmissionController::class, 'unit'])->whereNumber('id')->name('fiscal.product.unit');
            Route::get('fiscal/products/{id}', [FiscalEmissionController::class,'product'])->whereNumber('id')->name('fiscal.product');
            Route::post('fiscal/products/{id}', [FiscalEmissionController::class,'saveProduct'])->whereNumber('id')->name('fiscal.product.save');
            Route::post('sales/{id}/fiscal/emit', [FiscalEmissionController::class,'emit'])->whereNumber('id')->name('fiscal.emit');
            Route::get('fiscal/documents/{id}', [FiscalEmissionController::class,'show'])->whereNumber('id')->name('fiscal.document');
            Route::post('fiscal/documents/{id}/consult', [FiscalEmissionController::class,'consult'])->whereNumber('id')->name('fiscal.consult');
            Route::post('fiscal/documents/{id}/resend', [FiscalEmissionController::class,'resend'])->whereNumber('id')->name('fiscal.resend');
            Route::post('fiscal/documents/{id}/cancel', [FiscalEmissionController::class,'cancel'])->whereNumber('id')->name('fiscal.cancel');
            Route::get('fiscal/documents/{id}/files/{kind}', [FiscalEmissionController::class,'download'])->whereNumber('id')->name('fiscal.download');
            Route::post('fiscal/settings', [FiscalController::class, 'save'])->name('fiscal.settings');
            Route::get('sales/{id}/fiscal', [FiscalController::class, 'sale'])->whereNumber('id')->name('fiscal.sale');
            Route::post('sales/{id}/fiscal/prepare', [FiscalController::class, 'prepare'])->whereNumber('id')->name('fiscal.prepare');
            Route::get('fiscal/preparations/{id}', [FiscalController::class, 'preparation'])->whereNumber('id')->name('fiscal.preparation');
        });
        Route::get('purchases', [PurchaseController::class, 'index'])->name('purchases');
        Route::get('purchases/new', [PurchaseController::class, 'form'])->name('purchase.new');
        Route::post('purchases/preview-xml', [PurchaseController::class,'previewXml'])->name('purchase.preview-xml');
        Route::post('purchases', [PurchaseController::class, 'create']);
        Route::get('purchases/{id}', [PurchaseController::class, 'show'])->whereNumber('id')->name('purchase.show');
        Route::post('quick-create/{kind}',[\App\Http\Controllers\QuickCreateController::class,'store'])->whereIn('kind',['customers','products','services'])->name('quick.create');
        Route::get('desk',[DeskController::class,'index'])->name('desk');
        Route::get('sellers',[SellerController::class,'index'])->name('sellers');
        Route::post('sellers',[SellerController::class,'save'])->name('seller.save');
        Route::post('documents/{id}/seller',[OperationsController::class,'assignSeller'])->whereNumber('id')->name('document.seller');
        Route::get('my-commissions',[CommissionController::class,'mine'])->name('commissions.mine');
        Route::get('my-commissions/{id}',[CommissionController::class,'mineShow'])->whereNumber('id')->name('commissions.mine.show');
        Route::get('commissions/preview',[CommissionController::class,'preview'])->name('commission.preview');
        Route::post('commissions/close',[CommissionController::class,'close'])->name('commission.close');
        Route::get('commissions/closures/{id}',[CommissionController::class,'show'])->whereNumber('id')->name('commission.closure');
        Route::post('commissions/closures/{id}/pay',[CommissionController::class,'pay'])->whereNumber('id')->name('commission.pay');
        Route::get('commissions', [CommissionController::class, 'index'])->name('commissions');
        Route::get('reports', [ReportController::class, 'index'])->name('reports');
        Route::get('reports/export/{kind}', [ReportController::class, 'csv'])->name('report.csv');
        Route::get('products', [CommerceController::class, 'products'])->name('products');
        Route::get('products/new', [CommerceController::class, 'productForm'])->name('product.new');
        Route::get('measure-units', [\App\Http\Controllers\MeasureUnitController::class,'index'])->name('units');
        Route::post('measure-units', [\App\Http\Controllers\MeasureUnitController::class,'store'])->name('units.store');
        Route::post('products/{id}/units', [\App\Http\Controllers\MeasureUnitController::class,'store'])->whereNumber('id')->name('product.units');
        Route::get('products/{id}/edit', [CommerceController::class, 'productForm'])->whereNumber('id')->name('product.edit');
        Route::post('products/save/{id?}', [CommerceController::class, 'saveProduct'])->whereNumber('id')->name('product.save');
        Route::post('catalog/customers/cnpj', [CommerceController::class, 'lookupCustomerRegistry'])
            ->middleware('throttle:10,1')->name('customer.cnpj');
        Route::get('catalog/{kind}', [CommerceController::class, 'catalog'])->name('catalog');
        Route::post('catalog/{kind}', [CommerceController::class, 'saveCatalog']);
        Route::get('stock', [CommerceController::class, 'stock'])->name('stock');
        Route::post('stock', [CommerceController::class, 'moveStock']);
        Route::get('sales', [CommerceController::class, 'sales'])->name('sales');
        Route::get('sales/new', [CommerceController::class, 'saleForm'])->name('sale.new');
        Route::post('sales', [CommerceController::class, 'checkout']);
        Route::get('sales/{id}', [CommerceController::class, 'sale'])->whereNumber('id')->name('sale.show');
        Route::post('sales/{id}/cancel', [CommerceController::class, 'cancel'])->whereNumber('id')->name('sale.cancel');
        Route::post('sales/{id}/receive', [CommerceController::class, 'receive'])->whereNumber('id')->name('sale.receive');

        Route::get('documents', [OperationsController::class, 'documents'])->name('documents');
        Route::get('documents/new', [OperationsController::class, 'form'])->name('document.new');
        Route::post('documents', [OperationsController::class, 'create']);
        Route::get('documents/{id}/edit', [OperationsController::class, 'edit'])->whereNumber('id')->name('document.edit');
        Route::post('documents/{id}/update', [OperationsController::class, 'update'])->whereNumber('id')->name('document.update');
        Route::get('documents/{id}', [OperationsController::class, 'show'])->whereNumber('id')->name('document.show');
        Route::post('documents/{id}/status', [OperationsController::class, 'transition'])->whereNumber('id')->name('document.status');
        Route::post('documents/{id}/invoice', [OperationsController::class, 'invoice'])->whereNumber('id')->name('document.invoice');
        Route::get('finance', [OperationsController::class, 'finance'])->name('finance');
        Route::post('finance', [OperationsController::class, 'createEntry']);
        Route::post('finance/{id}/pix/{operation}', [\App\Http\Controllers\SicoobPixController::class, 'sync'])
            ->whereNumber('id')->whereIn('operation', ['gerar', 'consultar'])->middleware('throttle:30,1')->name('finance.pix');
        Route::post('finance/{id}/pay', [OperationsController::class, 'payEntry'])->whereNumber('id')->name('finance.pay');
        Route::get('payment-methods',[\App\Http\Controllers\PaymentMethodController::class,'index'])->name('payment.methods');
        Route::post('payment-methods/card-brands',[\App\Http\Controllers\PaymentMethodController::class,'saveBrand'])->name('payment.brands.save');
        Route::post('payment-methods',[\App\Http\Controllers\PaymentMethodController::class,'save'])->name('payment.methods.save');
        Route::get('access-profiles', [\App\Http\Controllers\AccessProfileController::class, 'index'])->name('access.profiles');
        Route::post('access-profiles', [\App\Http\Controllers\AccessProfileController::class, 'save'])->name('access.profiles.save');
        Route::post('access-profiles/delete', [\App\Http\Controllers\AccessProfileController::class, 'delete'])->name('access.profiles.delete');
        Route::get('users', [CommerceController::class, 'users'])->name('users');
        Route::post('users', [CommerceController::class, 'saveUser']);
    });
});

// Read-only archive of previously prepared local declarations.
Route::prefix('app/{company}')->where(['company' => '[a-z0-9-]+'])->middleware([ResolveTenant::class, TenantAuthenticated::class, EnforceContractAccess::class])->group(function () {
    Route::get('nfse', [\App\Http\Controllers\NfseController::class, 'index'])->name('nfse.index');
    Route::get('nfse/sales/{id}', [\App\Http\Controllers\NfseController::class, 'sale'])->whereNumber('id')->name('nfse.sale');
    Route::get('nfse/documents/{id}', [\App\Http\Controllers\NfseController::class, 'show'])->whereNumber('id')->name('nfse.show');
    Route::get('nfse/documents/{id}/xml', [\App\Http\Controllers\NfseController::class, 'xml'])->whereNumber('id')->name('nfse.xml');
});

require __DIR__.'/platform.php';
