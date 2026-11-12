@php($reportName=$companyProfile?->legal_name?:$tenant->name)
<div class="company-report-header {{ ($reportPrintOnly??false)?'report-print-only':'' }}">
    <img src="{{ route('business.logo',['company'=>$tenant->slug]) }}" alt="Logo de {{ $reportName }}" width="184" height="64">
    <div class="report-company-data"><strong>{{ $reportName }}</strong>
        @if($companyProfile?->trade_name && $companyProfile->trade_name!==$reportName)<span>{{ $companyProfile->trade_name }}</span>@endif
        <span>@if($companyProfile?->document)CNPJ {{ $companyProfile->document }}@endif @if($companyProfile?->state_registration) · IE {{ $companyProfile->state_registration }}@endif @if($companyProfile?->municipal_registration) · IM {{ $companyProfile->municipal_registration }}@endif</span>
        <span>{{ collect([$companyProfile?->street,$companyProfile?->number,$companyProfile?->district,$companyProfile?->city,$companyProfile?->state,$companyProfile?->postal_code])->filter(fn($v)=>filled($v))->implode(' · ') }}</span>
        <span>{{ collect([$companyProfile?->phone,$companyProfile?->email])->filter(fn($v)=>filled($v))->implode(' · ') }}</span>
    </div>
</div>
