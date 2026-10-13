@extends('layouts.app')

@section('title', 'Firewall · '.$server->name)

@section('content')
<div class="page-shell">
    <div class="page-header"><div><p class="eyebrow">Servidor</p><h1>Firewall · {{ $server->name }}</h1><p>Fase 2: configuração e validação somente leitura. Nenhuma regra será aplicada.</p></div></div>
    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="alert alert-error"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <section class="panel">
        <form method="POST" action="{{ route('servers.firewall.update', $server) }}">
            @csrf @method('PUT')
            <div class="form-grid">
                <label>ACLs administrativas (uma por linha)<textarea name="administrative_acls" rows="7" required placeholder="192.0.2.10/32&#10;2001:db8::10/128">{{ old('administrative_acls', implode("\n", $policy?->administrative_acls ?? [])) }}</textarea></label>
                <label>Portas administrativas<textarea name="administrative_ports" rows="7" placeholder="tcp/22&#10;tcp/443">{{ old('administrative_ports', implode("\n", $policy?->administrative_ports ?? [])) }}</textarea></label>
                <label>Portas públicas<textarea name="public_ports" rows="7" placeholder="tcp/53&#10;udp/53">{{ old('public_ports', implode("\n", $policy?->public_ports ?? [])) }}</textarea></label>
            </div>
            <p>Redes administrativas abertas em <code>/0</code> são recusadas. DNS TCP/UDP 53 e tráfego de saída são invariantes do contrato.</p>
            <button class="button button-primary" type="submit">Salvar política</button>
        </form>
    </section>
    @if ($policy)
    <section class="panel">
        <h2>Prévia do contrato</h2><p>Hash desejado: <code>{{ $policy->desired_hash }}</code></p>
        <form method="POST" action="{{ route('servers.firewall.validate', $server) }}" data-firewall-validation>@csrf<button class="button" type="submit">Validar com nft -c (não aplicar)</button></form>
        <p data-firewall-status>@if($latest)Última validação: {{ $latest->status }}@elseAinda não validada pelo agente.@endif</p>
    </section>
    @endif
</div>
@if($policy)<script nonce="{{ $cspNonce ?? '' }}">document.querySelector('[data-firewall-validation]')?.addEventListener('submit',async function(e){e.preventDefault();const b=this.querySelector('button');b.disabled=true;const r=await fetch(this.action,{method:'POST',headers:{'X-CSRF-TOKEN':this.querySelector('[name=_token]').value,'Accept':'application/json'}});const d=await r.json();document.querySelector('[data-firewall-status]').textContent=r.ok?'Validação autorizada; o agente executará no próximo ciclo.':(d.message||'Falha ao autorizar.');b.disabled=false;});</script>@endif
@endsection
