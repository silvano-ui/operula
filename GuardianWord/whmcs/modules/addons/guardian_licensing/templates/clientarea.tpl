<h2>Guardian Licensing</h2>

{if $error}
  <div class="alert alert-danger">{$error}</div>
{/if}

{if !$licenses || count($licenses) == 0}
  <div class="alert alert-info">Nessuna licenza trovata.</div>
{else}
  <p>Qui trovi le tue licenze/token per attivare il plugin WordPress Guardian.</p>
  <table class="table table-striped">
    <thead>
      <tr>
        <th>Service ID</th>
        <th>License ID</th>
        <th>Domain</th>
        <th>Plan</th>
        <th>Modules</th>
        <th>Status</th>
        <th>Expires</th>
        <th>Token</th>
      </tr>
    </thead>
    <tbody>
      {foreach from=$licenses item=lic}
        <tr>
          <td>{$lic.service_id}</td>
          <td>{$lic.license_id}</td>
          <td>{$lic.domain}</td>
          <td>{if $lic.plan}{$lic.plan}{else}-{/if}</td>
          <td>
            {if $lic.modules}
              {implode(', ', $lic.modules)}
            {else}
              -
            {/if}
          </td>
          <td>{$lic.status}</td>
          <td>{if $lic.expires_at > 0}{$lic.expires_at|date_format:"%Y-%m-%d"}{else}never{/if}</td>
          <td>
            {if $lic.token}
              <textarea readonly rows="3" style="width:100%;max-width:600px">{$lic.token}</textarea>
            {else}
              <em>Token non disponibile (domain mancante o stato non attivo).</em>
            {/if}
          </td>
        </tr>
      {/foreach}
    </tbody>
  </table>
{/if}

