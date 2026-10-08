"use client";

import { useState, useTransition } from "react";
import type { Allocation, AllocationConflict, AdminBlock } from "@/lib/api";
import { ALLOCATION_COLORS, ALLOCATION_LABELS, ALLOCATIONS } from "@/lib/allocation";
import { saveAllocations } from "@/lib/actions/admin";
import StadiumCanvas from "@/components/StadiumCanvas";

type Notice = { kind: "success" | "error" | "info"; text: string };

function conflictReason(c: AllocationConflict): string {
  if (c.reason === "stale") return `他の管理者が変更済み（現在: ${ALLOCATION_LABELS[c.current]}）`;
  return `チケットあり（販売済み ${c.tickets.paid} / 仮押さえ ${c.tickets.held}）`;
}

export default function AllocationEditor({
  matchId,
  editable,
  viewbox,
  blocks,
}: {
  matchId: number;
  editable: boolean;
  viewbox: { width: number; height: number };
  blocks: AdminBlock[];
}) {
  const [brush, setBrush] = useState<Allocation>("home");
  const [draft, setDraft] = useState<Map<number, Allocation>>(new Map());
  const [conflicts, setConflicts] = useState<AllocationConflict[]>([]);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [pending, startTransition] = useTransition();
  const [focusedBlockId, setFocusedBlockId] = useState<number | null>(null);

  const byId = new Map(blocks.map((b) => [b.id, b]));
  // props（元の値）と同じ下書きは差分に含めない
  const diffs = blocks.filter((b) => {
    const d = draft.get(b.id);
    return d !== undefined && d !== b.allocation;
  });
  const conflictById = new Map(conflicts.map((c) => [c.block_id, c]));
  const readOnly = !editable;

  function paint(b: AdminBlock) {
    if (pending) return;
    if (b.locked) {
      setNotice({
        kind: "info",
        text: `${b.code}: チケットがあるため変更できません（販売済み ${b.tickets.paid} / 仮押さえ ${b.tickets.held}）`,
      });
      return;
    }
    if (readOnly) return;
    setDraft((prev) => {
      const next = new Map(prev);
      if (brush === b.allocation) next.delete(b.id);
      else next.set(b.id, brush);
      return next;
    });
    setConflicts((prev) => prev.filter((c) => c.block_id !== b.id));
    setNotice(null);
  }

  function revert(id: number) {
    setDraft((prev) => {
      const next = new Map(prev);
      next.delete(id);
      return next;
    });
  }

  function save() {
    if (diffs.length === 0 || pending) return;
    const changes = diffs.map((b) => ({
      block_id: b.id,
      expected: b.allocation,
      allocation: draft.get(b.id) as Allocation,
    }));
    setNotice(null);
    setConflicts([]);
    startTransition(async () => {
      const res = await saveAllocations(matchId, changes);
      if (res.ok) {
        setDraft(new Map());
        setNotice({ kind: "success", text: `${changes.length} 件の割当を保存しました` });
        return;
      }
      const { code, message, details } = res.error;
      if (code === "allocation_conflict" && details?.blocks?.length) {
        const found = details.blocks;
        // 競合したブロックは下書きから外す。refresh() で props が最新になるので、
        // 他の管理者の変更を見ないまま上書きしないよう、現在値を見て塗り直してもらう
        setDraft((prev) => {
          const next = new Map(prev);
          for (const c of found) next.delete(c.block_id);
          return next;
        });
        setConflicts(found);
        setNotice({
          kind: "error",
          text: "競合したブロックがあり保存できませんでした。該当ブロックは下書きから外しました。内容を確認して塗り直してください。",
        });
      } else if (code === "match_locked") {
        setNotice({ kind: "error", text: message || "この試合は編集できません（キックオフ済み）" });
      } else if (code === "allocation_busy" || code === "busy") {
        setNotice({ kind: "error", text: message || "混み合っています。少し待ってからもう一度保存してください" });
      } else if (code === "forbidden") {
        setNotice({ kind: "error", text: message || "権限がありません" });
      } else {
        setNotice({ kind: "error", text: message || "保存に失敗しました" });
      }
    });
  }

  function onKeyDown(e: React.KeyboardEvent, b: AdminBlock) {
    if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      paint(b);
    }
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
      <div>
        {readOnly && (
          <p className="mb-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            この試合はキックオフ済みのため編集できません（読み取り専用）。
          </p>
        )}
        <div role="group" aria-label="ブラシ" className="mb-3 flex flex-wrap gap-2">
          {ALLOCATIONS.map((a) => (
            <button
              key={a}
              type="button"
              aria-pressed={brush === a}
              disabled={readOnly}
              onClick={() => setBrush(a)}
              className={`flex items-center gap-1.5 rounded border px-3 py-1.5 text-sm disabled:opacity-50 ${
                brush === a
                  ? "border-gray-900 bg-gray-100 font-semibold dark:border-gray-100 dark:bg-gray-800"
                  : "border-gray-300 dark:border-gray-600"
              }`}
            >
              <span
                className="inline-block h-3 w-3 rounded-sm"
                style={{ backgroundColor: ALLOCATION_COLORS[a] }}
              />
              {ALLOCATION_LABELS[a]}
            </button>
          ))}
        </div>
        <StadiumCanvas viewbox={viewbox}>
          <defs>
            <pattern id="locked-hatch" width={6} height={6} patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
              <rect width={6} height={6} fill="#000000" fillOpacity={0.25} />
              <line x1={0} y1={0} x2={0} y2={6} stroke="#ffffff" strokeWidth={2} />
            </pattern>
          </defs>
          {blocks.map((b) => {
            const current = draft.get(b.id) ?? b.allocation;
            const changed = current !== b.allocation;
            const conflict = conflictById.get(b.id);
            const disabled = readOnly || b.locked;
                        const label =
              `${b.code} 現在の割当: ${ALLOCATION_LABELS[current]}` +
              (changed ? "（未保存）" : "") +
              (b.locked ? ` ロック中（チケットあり: 販売済み ${b.tickets.paid} / 仮押さえ ${b.tickets.held}）` : "");
            return (
              <g
                key={b.id}
                role="button"
                tabIndex={0}
                aria-label={label}
                aria-disabled={disabled}
                data-block-code={b.code}
                data-allocation={current}
                data-locked={b.locked}
                onClick={() => paint(b)}
                onKeyDown={(e) => onKeyDown(e, b)}
                onFocus={(e) => setFocusedBlockId(e.currentTarget.matches(":focus-visible") ? b.id : null)}
                onBlur={() => setFocusedBlockId(null)}
                className={`group outline-none ${disabled ? "cursor-not-allowed" : "cursor-pointer"}`}
              >
                <title>
                  {b.locked ? `${b.code}: 販売済み ${b.tickets.paid} / 仮押さえ ${b.tickets.held}` : b.code}
                </title>
                <rect
                  x={b.geometry.x}
                  y={b.geometry.y}
                  width={b.geometry.w}
                  height={b.geometry.h}
                  rx={4}
                  fill={ALLOCATION_COLORS[current]}
                  stroke={conflict ? "#f59e0b" : changed ? undefined : "#ffffff"}
                  strokeWidth={conflict || changed ? 3 : 1}
                  strokeDasharray={conflict ? "5 3" : changed ? "6 3" : undefined}
                  className={changed && !conflict ? "stroke-gray-900 dark:stroke-white" : undefined}
                />
                {b.locked && (
                  <rect
                    x={b.geometry.x}
                    y={b.geometry.y}
                    width={b.geometry.w}
                    height={b.geometry.h}
                    rx={4}
                    fill="url(#locked-hatch)"
                    pointerEvents="none"
                  />
                )}
                <text
                  x={b.geometry.x + b.geometry.w / 2}
                  y={b.geometry.y + b.geometry.h / 2}
                  textAnchor="middle"
                  dominantBaseline="middle"
                  fontSize={11}
                  fill="#ffffff"
                  pointerEvents="none"
                >
                  {b.code}
                </text>
              </g>
            );
          })}
          {/* キーボードフォーカス: 外側の水色リング（全ブロックの上に描画） */}
          {focusedBlockId !== null && (() => {
            const b = byId.get(focusedBlockId);
            return b ? (
              <rect
                x={b.geometry.x - 4}
                y={b.geometry.y - 4}
                width={b.geometry.w + 8}
                height={b.geometry.h + 8}
                rx={7}
                fill="none"
                stroke="#0ea5e9"
                strokeWidth={3}
                pointerEvents="none"
              />
            ) : null;
          })()}
        </StadiumCanvas>
        <p className="mt-2 text-xs text-gray-500">
          斜線のブロックはチケットがあるため変更できません。白（ダーク時）/黒（ライト時）の破線枠は未保存の変更、橙の破線枠は競合、水色の外枠はキーボードフォーカスです。ロックされたブロックをタップすると枚数を表示します。
        </p>
      </div>

      <aside className="space-y-3">
        <div className="flex items-center justify-between">
          <h2 className="font-semibold">変更（{diffs.length} 件）</h2>
          <button
            type="button"
            onClick={() => setDraft(new Map())}
            disabled={diffs.length === 0 || pending}
            className="text-sm text-blue-600 underline disabled:text-gray-400 disabled:no-underline"
          >
            全て取り消し
          </button>
        </div>
        {diffs.length === 0 ? (
          <p className="text-sm text-gray-500">変更はありません。</p>
        ) : (
          <ul className="space-y-1 text-sm">
            {diffs.map((b) => {
              const c = conflictById.get(b.id);
              return (
                <li
                  key={b.id}
                  className={`flex items-center justify-between gap-2 rounded border px-2 py-1 ${
                    c ? "border-amber-400 bg-amber-50 dark:bg-amber-950" : "border-gray-200 dark:border-gray-700"
                  }`}
                >
                  <span>
                    {b.code}: {ALLOCATION_LABELS[b.allocation]} → {ALLOCATION_LABELS[draft.get(b.id) as Allocation]}
                  </span>
                  <button
                    type="button"
                    onClick={() => revert(b.id)}
                    disabled={pending}
                    aria-label={`${b.code} の変更を取り消し`}
                    className="text-blue-600 underline disabled:text-gray-400"
                  >
                    取消
                  </button>
                </li>
              );
            })}
          </ul>
        )}

        {conflicts.length > 0 && (
          <ul className="space-y-1 rounded border border-amber-400 bg-amber-50 p-2 text-sm text-amber-900">
            {conflicts.map((c) => (
              <li key={c.block_id}>
                {byId.get(c.block_id)?.code ?? `#${c.block_id}`}: {conflictReason(c)}
              </li>
            ))}
          </ul>
        )}

        <button
          type="button"
          onClick={save}
          disabled={readOnly || pending || diffs.length === 0}
          className="w-full rounded bg-blue-600 px-4 py-2 text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-400"
        >
          {pending ? "保存中…" : "保存"}
        </button>
        <div role="status" aria-live="polite">
          {notice && (
            <p
              className={`text-sm ${
                notice.kind === "success"
                  ? "text-green-700 dark:text-green-400"
                  : notice.kind === "info"
                    ? "text-gray-700 dark:text-gray-300"
                    : "text-red-600"
              }`}
            >
              {notice.text}
            </p>
          )}
        </div>
      </aside>
    </div>
  );
}
