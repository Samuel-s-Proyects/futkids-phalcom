// Event delegation. Views register handlers by name instead of attaching listeners to rendered markup:
//   onAction('schedule-match', (button) => …)   for <button data-action="schedule-match">
//   onSubmit('schedule-form', (form) => …)      for <form id="schedule-form">
//   onChange('team-filter', (input) => …)       for any input/select with data-change="team-filter"
import { toast } from '../ui/toast.js';

const actions = new Map();
const submits = new Map();
const changes = new Map();

export const onAction = (name, handler) => actions.set(name, handler);
export const onSubmit = (formId, handler) => submits.set(formId, handler);
export const onChange = (name, handler) => changes.set(name, handler);

/** Runs a handler, reporting unexpected failures instead of leaving the interface silent. */
async function run(handler, ...args) {
  try {
    await handler(...args);
  } catch (error) {
    toast(error.message || 'Ocurrió un error inesperado.', { error: true });
    if (!error.status) console.error(error);
  }
}

export function listen(root = document) {
  root.addEventListener('click', event => {
    const trigger = event.target.closest('[data-action]');
    if (!trigger || trigger.disabled || trigger.getAttribute('aria-disabled') === 'true') return;
    const handler = actions.get(trigger.dataset.action);
    if (!handler) return;
    event.preventDefault();
    run(handler, trigger, event);
  });
  root.addEventListener('submit', event => {
    const handler = submits.get(event.target.id);
    if (!handler) return;
    event.preventDefault();
    run(handler, event.target, event);
  });
  const changed = event => {
    const name = event.target.dataset?.change;
    const handler = name && changes.get(name);
    if (handler) run(handler, event.target, event);
  };
  root.addEventListener('change', changed);
  root.addEventListener('input', event => { if (event.target.dataset?.live === 'input') changed(event); });
}
