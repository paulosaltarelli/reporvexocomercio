<?php
namespace App\Access;

use Illuminate\Http\Request;

final class Permissions
{
    public const GROUPS = [
        'Balcão e vendas' => ['desk.use'=>'Abrir a frente de caixa','sales.view'=>'Consultar vendas e comprovantes da empresa','sales.create'=>'Registrar vendas','sales.cancel'=>'Cancelar vendas e estornar','sales.receive'=>'Receber o saldo integral de uma venda','sales.seller'=>'Escolher outro vendedor'],
        'Orçamentos e OS' => ['documents.view'=>'Consultar orçamentos e OS da empresa','documents.create'=>'Criar orçamentos e OS','documents.edit'=>'Editar OS abertas','documents.status'=>'Alterar etapas e cancelar documentos','documents.invoice'=>'Gerar venda a partir de orçamento/OS','documents.seller'=>'Reatribuir vendedor com justificativa'],
        'Cadastros' => ['products.view'=>'Consultar produtos e serviços','products.manage'=>'Cadastrar e editar produtos/serviços','customers.view'=>'Consultar clientes','customers.manage'=>'Cadastrar e editar clientes','suppliers.view'=>'Consultar fornecedores','suppliers.manage'=>'Cadastrar e editar fornecedores','groups.view'=>'Consultar grupos','groups.manage'=>'Alterar grupos e regras comerciais','sellers.manage'=>'Gerenciar vendedores'],
        'Estoque e preços' => ['stock.view'=>'Consultar saldos e movimentações','stock.manage'=>'Movimentar estoque','costs.view'=>'Visualizar custos, margens e sugestões','prices.view'=>'Consultar preços e alertas','prices.manage'=>'Aplicar preços e atualizar regras','purchases.view'=>'Consultar compras e custos','purchases.manage'=>'Registrar compras'],
        'Financeiro e caixa' => ['finance.view'=>'Consultar contas a pagar e receber','finance.manage'=>'Criar contas e registrar baixas','cash.view'=>'Consultar caixa diário','cash.close'=>'Fechar caixa','cash.manage'=>'Registrar abertura, sangria e suprimento','cashflow.view'=>'Consultar fluxo e previsão'],
        'Gestão e fiscal' => ['dashboard.view'=>'Visualizar indicadores gerenciais','reports.view'=>'Consultar relatórios gerenciais','reports.export'=>'Exportar relatórios CSV','commissions.view'=>'Consultar comissões de toda a equipe','commissions.close'=>'Fechar comissões e gerar contas a pagar','commissions.pay'=>'Registrar pagamentos de comissões','fiscal.manage'=>'Operar fiscal: consulta, configuração, emissão e cancelamento'],
    ];
    public const NAVIGATION = [
        ['Visão geral','dashboard',[],'dashboard.view'],['Frente de caixa','desk',[],'desk.use'],
        ['Vendas','sales',[],'sales.view'],['Orçamentos e OS','documents',[],'documents.view'],
        ['Produtos e serviços','products',[],'products.view'],['Clientes','catalog',['kind'=>'customers'],'customers.view'],['Fornecedores','catalog',['kind'=>'suppliers'],'suppliers.view'],['Grupos','catalog',['kind'=>'groups'],'groups.view'],
        ['Vendedores','sellers',[],'sellers.manage'],['Movimentações de estoque','stock',[],'stock.view'],['Compras','purchases',[],'purchases.view'],['Preços e alertas','prices',[],'prices.view'],
        ['Contas a pagar e receber','finance',[],'finance.view'],['Caixa diário','cash',[],'cash.view'],['Fluxo e previsão','cashflow',[],'cashflow.view'],['Relatórios gerenciais','reports',[],'reports.view'],['Comissões','commissions',[],'commissions.view'],['Fiscal','fiscal',[],'fiscal.manage'],
    ];
    public const DEPENDENCIES = [
        'sales.create'=>['desk.use','products.view','sales.view'], 'sales.cancel'=>['sales.view'], 'sales.receive'=>['sales.view'],
        'documents.create'=>['documents.view','products.view'], 'documents.edit'=>['documents.view','products.view'], 'documents.status'=>['documents.view'], 'documents.invoice'=>['documents.view','products.view','sales.view'], 'documents.seller'=>['documents.view','sales.seller'],
        'products.manage'=>['products.view','groups.view','costs.view'],'customers.manage'=>['customers.view'],'suppliers.manage'=>['suppliers.view'],'groups.manage'=>['groups.view','costs.view'],
        'stock.manage'=>['stock.view','costs.view'],'prices.view'=>['costs.view'],'prices.manage'=>['prices.view'],'purchases.view'=>['costs.view'],'purchases.manage'=>['purchases.view','products.view','suppliers.view'],
        'commissions.close'=>['commissions.view','finance.view'],'commissions.pay'=>['commissions.close','finance.manage'],'fiscal.manage'=>['sales.view'],'finance.manage'=>['finance.view'],'cash.close'=>['cash.view'],'cash.manage'=>['cash.view'],'reports.view'=>['costs.view'],'reports.export'=>['reports.view'],'dashboard.view'=>['costs.view','sales.view','stock.view','finance.view'],
    ];
    public const OPERATOR = ['desk.use','sales.view','sales.create','documents.view','documents.create','documents.edit','documents.status','documents.invoice','products.view','customers.view','suppliers.view','groups.view','stock.view'];
    public static function labels(): array { return array_merge(...array_values(self::GROUPS)); }
    public static function expand(array $permissions): array
    {
        $out=array_fill_keys($permissions,true);
        do { $before=count($out);foreach(array_keys($out) as $key)foreach(self::DEPENDENCIES[$key]??[] as $dependency)$out[$dependency]=true; } while(count($out)!==$before);
        $keys=array_keys($out);sort($keys);return $keys;
    }
    public static function request(Request $request): string
    {
        $route=$request->route();
        return self::action(class_basename($route?->getControllerClass()??''),$route?->getActionMethod()??'',(string)$request->route('kind'));
    }
    public static function named(string $name,array $parameters=[]): string
    {
        $route=app('router')->getRoutes()->getByName($name);
        return self::action(class_basename($route?->getControllerClass()??''),$route?->getActionMethod()??'',(string)($parameters['kind']??''));
    }
    private static function action(string $controller,string $action,string $kind): string
    {
        if (in_array($controller,['FiscalController','FiscalEmissionController','NfeEmissionController','FiscalProfileController','SimulationFiscalController','NfseController'],true)) return 'fiscal.manage';
        return match($controller) {
            'AuthController','LicenseHelpController'=>'account.self',
            'AccountController'=>$action==='updateUser'?'administration': 'account.self',
            'BusinessController'=>match($action){'logo'=>'account.self','fiscal'=>'fiscal.manage',default=>'administration'},
            'LicenseSelfServiceController','CompanySetupController','AccessProfileController','PaymentMethodController'=>'administration',
            'SicoobPixController'=>'finance.manage',
            'DeskController'=>'desk.use',
            'MeasureUnitController'=>'products.manage',
            'QuickCreateController'=>match($kind){'customers'=>'customers.manage','products','services'=>'products.manage',default=>'denied'},
            'CommerceController'=>match($action) {
                'dashboard'=>'home', 'products'=>'products.view', 'productForm','saveProduct'=>'products.manage',
                'lookupCustomerRegistry'=>'customers.manage',
                'catalog','saveCatalog'=>match($kind) { 'customers'=>'customers.', 'suppliers'=>'suppliers.', 'groups'=>'groups.', 'services'=>'products.', default=>'denied.' }.($action==='catalog'?'view':'manage'),
                'stock'=>'stock.view','moveStock'=>'stock.manage','sales','sale'=>'sales.view','saleForm','checkout'=>'sales.create','cancel'=>'sales.cancel','receive'=>'sales.receive',
                'users','saveUser'=>'administration',default=>'denied',
            },
            'OperationsController'=>match($action) { 'documents','show'=>'documents.view','form','create'=>'documents.create','edit','update'=>'documents.edit','transition'=>'documents.status','invoice'=>'documents.invoice','assignSeller'=>'documents.seller','finance'=>'finance.view','createEntry','payEntry'=>'finance.manage',default=>'denied' },
            'SellerController'=>'sellers.manage',
            'PricingController'=>$action==='index'?'prices.view':'prices.manage',
            'PurchaseController'=>in_array($action,['index','show'],true)?'purchases.view':'purchases.manage',
            'CashController'=>match($action){'index'=>'cash.view','close'=>'cash.close','open','movement'=>'cash.manage',default=>'denied'},
            'CashflowController'=>'cashflow.view','CommissionController'=>match($action){'mine'=>'commissions.self','show'=>'commissions.view','mineShow'=>'commissions.self','preview','close'=>'commissions.close','pay'=>'commissions.pay','index'=>'commissions.view',default=>'denied'},
            'ReportController'=>$action==='csv'?'reports.export':'reports.view',
            default=>'denied',
        };
    }
}
