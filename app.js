const userSelect = document.getElementById("user-select");
const sessionInput = document.getElementById("session-input");
const machineSelect = document.getElementById("machine-select");
const recentSets = document.getElementById("recent-sets");
const entryForm = document.getElementById("entry-form");
const entryInput = document.getElementById("entry-input");
const entryFeedback = document.getElementById("entry-feedback");

let data = { machines: [], sets: [] };
let currentLifter = null;

fetch("data.php")
  .then((res) => res.json())
  .then((json) => {
    data = json;
    for (const machine of data.machines) {
      const option = document.createElement("option");
      option.value = machine.id;
      option.textContent = machine.name;
      machineSelect.appendChild(option);
    }
  });

userSelect.addEventListener("change", () => {
  currentLifter = userSelect.value;
  sessionInput.hidden = !currentLifter;
  updateRecentSets();
});

machineSelect.addEventListener("change", updateRecentSets);

entryForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  entryFeedback.textContent = "";

  if (!currentLifter || !machineSelect.value) {
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
        machine_id: Number(machineSelect.value),
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
  if (!machineSelect.value) {
    recentSets.replaceChildren();
    return;
  }

  const machineId = Number(machineSelect.value);
  const recent = data.sets
    .filter((s) => s.lifter === currentLifter && s.machine_id === machineId)
    .sort((a, b) => b.logged_at.localeCompare(a.logged_at) || b.id - a.id)
    .slice(0, 3);

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
