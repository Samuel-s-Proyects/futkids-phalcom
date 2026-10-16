import { api } from '../../core/api.js';
import { esc } from '../../core/dom.js';
import { onAction } from '../../core/events.js';
import { date } from '../../core/format.js';
import { store } from '../../core/store.js';
import { avatar, badge, button, emptyState, table } from '../../ui/components.js';
import { dialogHeader, openDialog } from '../../ui/dialog.js';

export async function openPlayerFile(id) {
  const p = await api(`/player/${id}`);
  const history = p.history.filter(m => m.goals || m.suspended);
  openDialog(`${dialogHeader({ eyebrow: 'Ficha del jugador', title: p.name, subtitle: p.team_name, size: 'wide' })}
    <div class="dialog__body">
      <div class="profile">${avatar(p, true)}<div class="stack"><h2>${esc(p.name)}</h2><div class="cluster">${badge(p.position ?? 'Sin posición', 'info')}${p.shirt_number ? badge('#' + p.shirt_number) : ''}</div></div></div>
      <div class="facts">
        <div><span>Nacimiento</span><strong>${esc(date(p.birth_date))}</strong></div>
        <div><span>Edad al ${esc(date(store.settings.age_reference_date))}</span><strong>${p.age} años</strong></div>
        <div><span>Goles</span><strong>${p.total_goals}</strong></div>
        <div><span>Tarjetas</span><strong>${p.incidents.length}</strong></div>
      </div>
      <section><h3>Tarjetas y suspensiones</h3>${p.incidents.length
        ? table([{ label: 'Tarjeta' }, { label: 'Descripción' }, { label: 'Fecha' }, { label: 'Suspensión' }], p.incidents.map(i => [badge(i.card_type === 'red' ? 'Roja' : 'Amarilla', i.card_type === 'red' ? 'danger' : 'warning'), esc(i.description), esc(date(i.incident_date)), esc(i.suspension_date ? date(i.suspension_date) : '—')]))
        : emptyState('Sin tarjetas', '', 'check')}</section>
      ${history.length ? `<section><h3>Histórico por jornada</h3>${table([{ label: 'Jornada' }, { label: 'Fecha' }, { label: 'Goles', numeric: true }, { label: 'Estado' }], history.map(m => [`Jornada ${m.number}`, esc(date(m.game_date)), String(m.goals), m.suspended ? badge('Suspendido', 'danger') : badge('Disponible', 'success')]))}</section>` : ''}
    </div>
    <div class="dialog__footer">${button('Imprimir', { action: 'print', iconName: 'print' })}<button class="button button--primary" type="button" data-action="close-dialog">Cerrar</button></div>`, 'wide');
}

onAction('player-file', trigger => openPlayerFile(+trigger.dataset.id));
