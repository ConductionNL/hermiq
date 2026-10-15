-- Field-by-field verifier for session-data-migration (task 3.1).
-- Reports one row per mismatch; zero rows means every field matched.
select c._uuid,
       case
         when s._uuid is null                                          then 'MISSING in agentsession'
         when coalesce(s.title,'')        is distinct from coalesce(c.title,'')        then 'title differs'
         when coalesce(s.user_id,'')      is distinct from coalesce(c.user_id,'')      then 'userId differs'
         when coalesce(s.agent_id,'')     is distinct from coalesce(c.agent_id,'')     then 'agentId differs'
         when (s._deleted is null) <> (c._deleted is null)             then 'ARCHIVE STATE differs'
         when s._deleted is not null and s._deleted->>'deletedAt' is distinct from c._deleted->>'deletedAt' then 'deletedAt differs'
         when s._deleted is not null and s._deleted->>'deletedBy' is distinct from c._deleted->>'deletedBy' then 'deletedBy differs'
         when coalesce(s._owner,'')       is distinct from coalesce(c._owner,'')       then 'owner differs'
         when coalesce(s._organisation,'') is distinct from coalesce(c._organisation,'') then 'organisation differs'
         when coalesce(s.trigger_origin,'') <> 'human'                 then 'triggerOrigin not human'
       end as mismatch
from oc_openregister_table_34_1160 c
left join oc_openregister_table_34_1154 s on s._uuid = c._uuid
where case
         when s._uuid is null                                          then 'x'
         when coalesce(s.title,'')        is distinct from coalesce(c.title,'')        then 'x'
         when coalesce(s.user_id,'')      is distinct from coalesce(c.user_id,'')      then 'x'
         when coalesce(s.agent_id,'')     is distinct from coalesce(c.agent_id,'')     then 'x'
         when (s._deleted is null) <> (c._deleted is null)             then 'x'
         when s._deleted is not null and s._deleted->>'deletedAt' is distinct from c._deleted->>'deletedAt' then 'x'
         when s._deleted is not null and s._deleted->>'deletedBy' is distinct from c._deleted->>'deletedBy' then 'x'
         when coalesce(s._owner,'')       is distinct from coalesce(c._owner,'')       then 'x'
         when coalesce(s._organisation,'') is distinct from coalesce(c._organisation,'') then 'x'
         when coalesce(s.trigger_origin,'') <> 'human'                 then 'x'
       end is not null;
