# shellcheck shell=sh
# shellcheck disable=SC3043
#
# .install/lib/ratchet.sh
#
# What the unprivileged lesta-agent-daemon is no longer allowed to do. Everything that changes the
# machine is applied by the root applier (see lib/applier.sh), so the daemon has no sudo rule, is not
# in the groups of the services whose files it used to write, and cannot write the directories root
# services load. These helpers move an existing node to that state: an installer calls them where it
# used to grant the access, and they also repair a node installed before.

# ratchet_root_owned_dir <path> <mode> <group>
# Creates path (or corrects it) as root:<group> with <mode>, and takes every file and directory
# inside that still belongs to lesta-agent, from the days it wrote there, for root. The group of
# what is inside is left alone.
ratchet_root_owned_dir() {
    local path="$1" mode="$2" group="$3"

    install -d -m "${mode}" -o root -g "${group}" "${path}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${path}" "failed to create ${path}"

    find "${path}" -user lesta-agent -exec chown -h root {} + 2>/dev/null || true
}

# ratchet_remove_sudoers <path>
# The daemon has no sudo rule any more; removes the rule an earlier installer wrote.
ratchet_remove_sudoers() {
    if [ -e "$1" ]; then
        rm -f "$1" || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "$1" "failed to remove the obsolete sudoers rule $1"
        add_change base.os.v1 removed "$1" "obsolete sudoers rule removed: lesta-agent no longer runs anything as root"
    fi
}

# ratchet_leave_group <user> <group>
# Takes user out of group when it is in it (and does nothing when the group or the membership is not there).
ratchet_leave_group() {
    local user="$1" group="$2"

    if getent group "${group}" >/dev/null 2>&1 && id -nG "${user}" 2>/dev/null | tr ' ' '\n' | grep -qx "${group}"; then
        gpasswd -d "${user}" "${group}" >/dev/null 2>&1 \
            || fail_step "${EXIT_MUTATION_FAILURE}" gpasswd_failed /etc/group "failed to remove ${user} from group ${group}"
        add_change base.os.v1 removed /etc/group "${user} removed from group ${group}: it no longer writes what that group's services read"
    fi
}
