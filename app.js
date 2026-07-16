const userSelect = document.getElementById("user-select");
const sessionInput = document.getElementById("session-input");
const machineInput = document.getElementById("machine-input");
const machineList = document.getElementById("machine-list");
const removeMachineButton = document.getElementById("remove-machine-button");
const recentSets = document.getElementById("recent-sets");
const entryForm = document.getElementById("entry-form");
const entryInput = document.getElementById("entry-input");
const entryFeedback = document.getElementById("entry-feedback");
const confirmModal = document.getElementById("confirm-modal");
const confirmModalMessage = document.getElementById("confirm-modal-message");
const confirmModalCancel = document.getElementById("confirm-modal-cancel");
const confirmModalConfirm = document.getElementById("confirm-modal-confirm");

let data = { machines: [], sets: [] };
let currentLifter = null;
let selectedMachineId = null;
let activeIndex = -1;

fetch("data.php")
  .then((res) => res.json())
  .then((json) => {
    data = json;
  });

userSelect.addEventListener("change", () => {
  currentLifter = userSelect.value;
  sessionInput.hidden = !currentLifter;
  updateRecentSets();
});

function filteredMachines() {
  const query = machineInput.value.trim().toLowerCase();
  if (!query) return data.machines;
  return data.machines.filter((m) => m.name.toLowerCase().includes(query));
}

function renderMachineList() {
  const query = machineInput.value.trim();
  const matches = filteredMachines();
  activeIndex = -1;

  const items = matches.map((machine) => {
    const li = document.createElement("li");
    li.textContent = machine.name;
    li.dataset.id = machine.id;
    li.addEventListener("mousedown", (event) => {
      event.preventDefault();
      selectMachine(machine);
    });
    return li;
  });

  const exactMatch = data.machines.some(
    (m) => m.name.toLowerCase() === query.toLowerCase()
  );
  if (query && !exactMatch) {
    const li = document.createElement("li");
    li.className = "add-machine";
    li.textContent = `+ Add "${query}"`;
    li.addEventListener("mousedown", (event) => {
      event.preventDefault();
      addMachine(query);
    });
    items.push(li);
  }

  if (items.length === 0) {
    const li = document.createElement("li");
    li.className = "empty";
    li.textContent = "No matching machines";
    machineList.replaceChildren(li);
    return;
  }

  machineList.replaceChildren(...items);
}

async function addMachine(name) {
  entryFeedback.textContent = "";
  try {
    const res = await fetch("add_machine.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ name }),
    });
    const result = await res.json();
    if (!result.ok) {
      entryFeedback.textContent = result.errors.join("; ");
      return;
    }
    data.machines.push(result.machine);
    data.machines.sort((a, b) => a.name.localeCompare(b.name));
    selectMachine(result.machine);
  } catch (err) {
    entryFeedback.textContent = "Failed to add machine: " + err.message;
  }
}

function openMachineList() {
  renderMachineList();
  machineList.hidden = false;
  machineInput.setAttribute("aria-expanded", "true");
}

function closeMachineList() {
  machineList.hidden = true;
  machineInput.setAttribute("aria-expanded", "false");
  activeIndex = -1;
}

function selectMachine(machine) {
  selectedMachineId = machine.id;
  machineInput.value = machine.name;
  closeMachineList();
  removeMachineButton.hidden = false;
  updateRecentSets();
}

function setActiveIndex(index) {
  const items = [...machineList.children].filter((li) => li.dataset.id);
  items.forEach((li) => li.classList.remove("active"));
  if (index < 0 || index >= items.length) {
    activeIndex = -1;
    return;
  }
  activeIndex = index;
  items[index].classList.add("active");
  items[index].scrollIntoView({ block: "nearest" });
}

machineInput.addEventListener("input", () => {
  selectedMachineId = null;
  removeMachineButton.hidden = true;
  openMachineList();
});

machineInput.addEventListener("focus", openMachineList);

machineInput.addEventListener("keydown", (event) => {
  const items = [...machineList.children].filter((li) => li.dataset.id);

  if (event.key === "ArrowDown") {
    event.preventDefault();
    if (machineList.hidden) {
      openMachineList();
      return;
    }
    setActiveIndex(Math.min(activeIndex + 1, items.length - 1));
  } else if (event.key === "ArrowUp") {
    event.preventDefault();
    setActiveIndex(Math.max(activeIndex - 1, 0));
  } else if (event.key === "Enter") {
    if (!machineList.hidden && activeIndex >= 0) {
      event.preventDefault();
      const machine = data.machines.find(
        (m) => String(m.id) === items[activeIndex].dataset.id
      );
      if (machine) selectMachine(machine);
    }
  } else if (event.key === "Escape") {
    closeMachineList();
  }
});

document.addEventListener("click", (event) => {
  if (!event.target.closest("#machine-combobox")) {
    closeMachineList();
  }
});

let confirmModalOnConfirm = null;

function openConfirmModal(message, onConfirm) {
  confirmModalMessage.textContent = message;
  confirmModalOnConfirm = onConfirm;
  confirmModal.hidden = false;
}

function closeConfirmModal() {
  confirmModal.hidden = true;
  confirmModalOnConfirm = null;
}

confirmModalCancel.addEventListener("click", closeConfirmModal);

confirmModalConfirm.addEventListener("click", () => {
  const onConfirm = confirmModalOnConfirm;
  closeConfirmModal();
  if (onConfirm) onConfirm();
});

confirmModal.addEventListener("click", (event) => {
  if (event.target === confirmModal) closeConfirmModal();
});

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && !confirmModal.hidden) closeConfirmModal();
});

removeMachineButton.addEventListener("click", () => {
  if (!selectedMachineId) return;
  const machine = data.machines.find((m) => m.id === selectedMachineId);
  const name = machine ? machine.name : "this machine";
  openConfirmModal(
    `Remove "${name}" and all of its logged sets? This can't be undone.`,
    () => deleteMachine(selectedMachineId)
  );
});

async function deleteMachine(machineId) {
  entryFeedback.textContent = "";
  try {
    const res = await fetch("delete_machine.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ machine_id: machineId }),
    });
    const result = await res.json();
    if (!result.ok) {
      entryFeedback.textContent = result.errors.join("; ");
      return;
    }
    data.machines = data.machines.filter((m) => m.id !== machineId);
    data.sets = data.sets.filter((s) => s.machine_id !== machineId);
    selectedMachineId = null;
    machineInput.value = "";
    removeMachineButton.hidden = true;
    updateRecentSets();
  } catch (err) {
    entryFeedback.textContent = "Failed to remove machine: " + err.message;
  }
}

entryForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  entryFeedback.textContent = "";

  if (!currentLifter || !selectedMachineId) {
    entryFeedback.textContent = "Choose a lifter and machine first.";
    return;
  }

  const expr = entryInput.value.trim();
  if (!expr) return;

  try {
    const res = await fetch("add.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        lifter: currentLifter,
        machine_id: Number(selectedMachineId),
        expr,
      }),
    });
    const result = await res.json();
    if (!result.ok) {
      entryFeedback.textContent = result.errors.join("; ");
      return;
    }
    data.sets.push(...result.sets);
    entryInput.value = "";
    updateRecentSets();
  } catch (err) {
    entryFeedback.textContent = "Failed to save: " + err.message;
  }
});

function updateRecentSets() {
  if (!selectedMachineId) {
    recentSets.replaceChildren();
    return;
  }

  const machineId = Number(selectedMachineId);
  const recent = data.sets
    .filter((s) => s.lifter === currentLifter && s.machine_id === machineId)
    .sort((a, b) => b.logged_at.localeCompare(a.logged_at) || b.id - a.id)
    .slice(0, 10);

  recentSets.replaceChildren(renderRecentSets(recent));
}

function renderRecentSets(rows) {
  if (rows.length === 0) {
    const p = document.createElement("p");
    p.textContent = "No previous sets for this machine.";
    return p;
  }

  const table = document.createElement("table");
  const thead = document.createElement("thead");
  thead.innerHTML =
    "<tr><th>Date</th><th>Reps</th><th>Weight</th><th>Sets</th><th>Comment</th><th></th></tr>";
  table.appendChild(thead);

  const tbody = document.createElement("tbody");
  for (const row of rows) {
    const tr = document.createElement("tr");

    const date = new Date(row.logged_at.replace(" ", "T") + "Z");
    const cells = [
      date.toLocaleString(undefined, {
        dateStyle: "medium",
        timeStyle: "short",
      }),
      row.reps,
      row.weight,
      row.num_sets,
      row.comment ?? "",
    ];

    for (const value of cells) {
      const td = document.createElement("td");
      td.textContent = value;
      tr.appendChild(td);
    }

    const actionTd = document.createElement("td");
    const deleteButton = document.createElement("button");
    deleteButton.type = "button";
    deleteButton.className = "delete-set";
    deleteButton.textContent = "\u{1F5D1}\u{FE0F}";
    deleteButton.setAttribute("aria-label", "Delete set");
    deleteButton.addEventListener("click", () => deleteSet(row.id));
    actionTd.appendChild(deleteButton);
    tr.appendChild(actionTd);

    tbody.appendChild(tr);
  }
  table.appendChild(tbody);
  return table;
}

async function deleteSet(id) {
  if (!confirm("Delete this set?")) return;

  entryFeedback.textContent = "";
  try {
    const res = await fetch("delete_set.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id }),
    });
    const result = await res.json();
    if (!result.ok) {
      entryFeedback.textContent = result.errors.join("; ");
      return;
    }
    data.sets = data.sets.filter((s) => s.id !== id);
    updateRecentSets();
  } catch (err) {
    entryFeedback.textContent = "Failed to delete: " + err.message;
  }
}
