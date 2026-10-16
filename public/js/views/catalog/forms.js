// Create/edit forms for teams, players and incidents (historic matchdays and goals are read-only).
import { api } from '../../core/api.js';
import { today } from '../../core/clock.js';
import { $, esc } from '../../core/dom.js';
import { onAction, onChange, onSubmit } from '../../core/events.js';
import { date, fullName } from '../../core/format.js';
import { rerender } from '../../core/router.js';
import { isAdmin, loadState, store, teamName } from '../../core/store.js';
import { button, photoUrl } from '../../ui/components.js';
import { closeDialogs, confirmDialog, dialogHeader, openDialog } from '../../ui/dialog.js';
import { field, requiredLegend, setControlError, submitForm } from '../../ui/form.js';
import { toast } from '../../ui/toast.js';

export const SINGULAR = { teams: 'equipo', players: 'jugador', incidents: 'incidencia' };
const MAX_PHOTO_BYTES = 2 * 1024 * 1024;

const teamOptions = ({ activeOnly = false, keep = 0 } = {}) => store.data.teams
  .filter(t => !activeOnly || +t.active || +t.id === +keep)
  .map(t => ({ value: t.id, label: `${t.name}${+t.active ? '' : ' (inactivo)'}` }));
const playerOptions = () => store.data.players.map(p => ({ value: p.id, label: `${fullName(p)} · ${teamName(p.team_id)}` }));

const FIELDS = {
  teams: r => [
    field({ name: 'name', label: 'Nombre del equipo', value: r.name, required: true, rule: 'title', maxlength: 100, full: true, placeholder: 'Ej.: Leones de Petapa', hint: 'Letras y números; puede separar con espacio, guion o punto. Sin símbolos como # @ / & %. No puede repetirse.' }),
    field({ name: 'active', label: 'Estado', type: 'select', value: r.active ?? 1, required: true, options: [{ value: 1, label: 'Activo' }, { value: 0, label: 'Inactivo' }], full: true, hint: 'Solo los equipos activos pueden inscribirse en torneos y recibir jugadores nuevos.' }),
  ],
  players: r => {
    const reference = store.settings.age_reference_date;
    const removable = r.id && /^uploads\/players\/[a-f0-9]{32}\.jpg$/.test(r.photo_path ?? '');
    return [
      `<div class="photo-upload field--full"><img id="photo-preview" src="${esc(photoUrl(r.photo_path))}" data-current="${esc(photoUrl(r.photo_path))}" alt="Vista previa de la fotografía">
        <div class="stack">${field({ name: 'photo', label: 'Fotografía', type: 'file', accept: 'image/jpeg,image/png', change: 'player-photo', hint: 'JPG o PNG de hasta 2 MB. Si no sube una, se usa una silueta.' })}
        ${removable ? '<label class="check"><input type="checkbox" name="remove_photo" value="1" data-change="remove-photo"> Quitar la fotografía actual</label>' : ''}</div></div>`,
      field({ name: 'first_name', label: 'Nombres', value: r.first_name, required: true, rule: 'person_name', maxlength: 100, autocomplete: 'off', hint: 'Solo letras; puede usar espacio, guion o apóstrofo (ej.: María José, Jean-Luc, O\'Brien).' }),
      field({ name: 'last_name', label: 'Apellidos', value: r.last_name, required: true, rule: 'person_name', maxlength: 100, autocomplete: 'off', hint: 'Solo letras, igual que los nombres.' }),
      field({ name: 'birth_date', label: 'Fecha de nacimiento', type: 'date', value: r.birth_date, required: true, max: today() < reference ? today() : reference, hint: `Edad permitida: de ${store.settings.age_min} a ${store.settings.age_max} años al ${date(reference)}.` }),
      field({ name: 'team_id', label: 'Equipo', type: 'select', value: r.team_id, required: true, placeholder: 'Seleccione un equipo', options: teamOptions({ activeOnly: true, keep: r.team_id }), hint: r.id ? 'Un jugador con goles o tarjetas no puede cambiar de equipo.' : 'Solo equipos activos.' }),
      field({ name: 'shirt_number', label: 'Número de camiseta', type: 'number', value: r.shirt_number ?? '', min: 1, max: 99, hint: 'Del 1 al 99, sin repetir dentro del mismo equipo.' }),
      field({ name: 'position', label: 'Posición', type: 'select', value: r.position ?? '', placeholder: 'Sin especificar', options: store.settings.positions.map(p => ({ value: p, label: p })) }),
    ];
  },
  incidents: r => [
    field({ name: 'player_id', label: 'Jugador', type: 'select', value: r.player_id, required: true, placeholder: 'Seleccione un jugador', options: playerOptions(), full: true }),
    field({ name: 'card_type', label: 'Tarjeta', type: 'select', value: r.card_type ?? 'yellow', required: true, change: 'manual-card', options: [{ value: 'yellow', label: 'Amarilla' }, { value: 'red', label: 'Roja (suspende un día)' }] }),
    field({ name: 'incident_date', label: 'Fecha de la incidencia', type: 'date', value: r.incident_date ?? today(), required: true, max: today(), change: 'manual-incident-date', hint: 'No puede ser una fecha futura.' }),
    `<div class="field--full" id="manual-suspension" ${r.card_type === 'red' ? '' : 'hidden'}>${field({ name: 'suspension_date', label: 'Fecha de suspensión', type: 'date', value: r.suspension_date ?? '', required: r.card_type === 'red', min: r.incident_date ?? today(), hint: 'El jugador no podrá jugar ese día. Igual o posterior a la incidencia.' })}</div>`,
    field({ name: 'description', label: 'Descripción', type: 'textarea', value: r.description, required: true, rule: 'description', maxlength: 500, full: true, hint: 'Qué ocurrió. Se permiten signos de puntuación comunes; no use < > { } [ ] @ # $ %.' }),
  ],
};

export function openRecordForm(entity, id = 0) {
  if (!isAdmin()) return;
  const record = store.data[entity].find(r => +r.id === +id) ?? {};
  openDialog(`${dialogHeader({ eyebrow: id ? 'Editar' : 'Nuevo registro', title: `${id ? 'Editar' : 'Registrar'} ${SINGULAR[entity]}` })}
    <form id="record-form" novalidate data-entity="${entity}" data-id="${id}"><div class="dialog__body">
      ${requiredLegend()}<div class="form-grid">${FIELDS[entity](record).join('')}</div>
      <p class="form-error" role="alert"></p>
    </div><div class="dialog__footer">
      <button class="button" type="button" data-action="close-dialog">Cancelar</button>
      ${button('Guardar', { type: 'submit', tone: 'primary', iconName: 'check' })}
    </div></form>`);
}

onAction('new-record', trigger => openRecordForm(trigger.dataset.entity));
onAction('edit-record', trigger => openRecordForm(trigger.dataset.entity, +trigger.dataset.id));

onAction('delete-record', async trigger => {
  const { entity, id } = trigger.dataset;
  const ok = await confirmDialog({ title: `¿Eliminar este ${SINGULAR[entity]}?`, text: 'Si tiene información asociada (goles, tarjetas, torneos), no se eliminará para proteger el historial.', confirmLabel: 'Eliminar', danger: true });
  if (!ok) return;
  await api(`/${entity}/${id}`, { method: 'DELETE' });
  await loadState();
  rerender();
  toast('Registro eliminado.');
});

onChange('manual-card', select => {
  const red = select.value === 'red';
  const wrapper = $('#manual-suspension', select.form);
  const input = select.form.elements.namedItem('suspension_date');
  wrapper.hidden = !red;
  input.required = red;
  if (!red) input.value = '';
});

onChange('manual-incident-date', input => {
  const suspension = input.form.elements.namedItem('suspension_date');
  if (suspension) suspension.min = input.value || today();
});

onChange('player-photo', input => {
  const file = input.files?.[0];
  const preview = $('#photo-preview');
  if (!file) { preview.src = preview.dataset.current; return; }
  if (file.size > MAX_PHOTO_BYTES || !['image/jpeg', 'image/png'].includes(file.type)) {
    setControlError(input, 'Seleccione un JPG o PNG de máximo 2 MB.');
    input.value = '';
    return;
  }
  setControlError(input, '');
  const remove = input.form.elements.namedItem('remove_photo');
  if (remove) remove.checked = false;
  preview.src = URL.createObjectURL(file);
});

onChange('remove-photo', checkbox => {
  const preview = $('#photo-preview');
  if (checkbox.checked) {
    checkbox.form.elements.namedItem('photo').value = '';
    preview.src = photoUrl('');
  } else preview.src = preview.dataset.current;
});

onSubmit('record-form', async form => {
  const { entity, id } = form.dataset;
  const saved = await submitForm(form, values => {
    const body = entity === 'players' ? new FormData(form) : values;
    return api(`/${entity}${+id ? '/' + id : ''}`, { method: 'POST', body });
  });
  if (!saved) return;
  await loadState();
  closeDialogs();
  rerender();
  toast(saved.message ?? 'Registro guardado.');
});
