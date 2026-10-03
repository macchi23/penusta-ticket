"use client";

import { switchDemoUser } from "@/lib/actions/demo";

type Props = {
  users: { id: number; name: string }[];
  currentId: number | null;
};

export default function DemoUserSwitcher({ users, currentId }: Props) {
  return (
    <form action={switchDemoUser} className="flex items-center gap-2">
      <select
        name="user_id"
        defaultValue={currentId ?? ""}
        className="rounded border border-amber-400 bg-white px-1 py-0.5 text-xs text-gray-900"
      >
        <option value="">選択解除</option>
        {users.map((u) => (
          <option key={u.id} value={u.id}>
            {u.name} (#{u.id})
          </option>
        ))}
      </select>
      <button
        type="submit"
        className="rounded bg-amber-500 px-2 py-0.5 text-xs font-semibold text-white hover:bg-amber-600"
      >
        切替
      </button>
    </form>
  );
}
