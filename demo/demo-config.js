'use strict';
window.CANTERA_DEMO=true;
window.CanteraTransport=(()=>{
 const key='cantera-demo-v1',userKey='cantera-demo-user';
 const settings={age_min:5,age_max:14,age_reference_date:'2026-10-03',demo_accounts:true};
 const initial=(async()=>{
   if(!localStorage.getItem(key)){const response=await fetch('/seed.json');if(!response.ok)throw new Error('No se pudieron cargar los datos de ejemplo.');localStorage.setItem(key,JSON.stringify(await response.json()));}
   const data=JSON.parse(localStorage.getItem(key));let changed=false;
   if(!Array.isArray(data.tournaments)){data.tournaments=[];changed=true;}
   if(!Array.isArray(data.tournament_teams)){data.tournament_teams=[];changed=true;}
   if(!Array.isArray(data.tournament_matches)){data.tournament_matches=[];changed=true;}
   if(!Array.isArray(data.tournament_match_period_scores)){data.tournament_match_period_scores=[];changed=true;}
   if(!Array.isArray(data.tournament_match_goals)){data.tournament_match_goals=[];changed=true;}
   if(!Array.isArray(data.tournament_match_penalties)){data.tournament_match_penalties=[];changed=true;}
   if(!Array.isArray(data.tournament_match_goal_events)){data.tournament_match_goal_events=[];changed=true;}
   if(!Array.isArray(data.tournament_match_result_audit)){data.tournament_match_result_audit=[];changed=true;}
   for(const match of data.tournament_matches)if(!match.workflow_status){match.workflow_status=match.status==='bye'?'bye':match.status==='completed'?'confirmed':match.scheduled_at?'scheduled':'unprogrammed';changed=true;}
   if(changed)localStorage.setItem(key,JSON.stringify(data));
 })();
 const read=()=>JSON.parse(localStorage.getItem(key));
 const user=()=>JSON.parse(sessionStorage.getItem(userKey)||'null');
 const clone=data=>JSON.parse(JSON.stringify(data));
 async function photoData(file){
   if(file.size>2*1024*1024||!['image/jpeg','image/png'].includes(file.type))throw new Error('Use una fotografía JPG o PNG de máximo 2 MB.');
   const source=await createImageBitmap(file);
   if(source.width*source.height>25000000){source.close();throw new Error('La fotografía excede 25 megapíxeles.');}
   const ratio=Math.min(1,640/Math.max(source.width,source.height)),canvas=document.createElement('canvas');
   canvas.width=Math.max(1,Math.round(source.width*ratio));canvas.height=Math.max(1,Math.round(source.height*ratio));
   const context=canvas.getContext('2d');context.fillStyle='#fff';context.fillRect(0,0,canvas.width,canvas.height);context.drawImage(source,0,0,canvas.width,canvas.height);source.close();return canvas.toDataURL('image/jpeg',.8);
 }
 return async(path,options={})=>{
   await initial;const url=new URL(path,location.origin),route=url.pathname,method=options.method||'GET';
   let body=options.body||{};
   if(body instanceof FormData){body=Object.fromEntries(body);if(body.photo instanceof File&&body.photo.size)body.photo_path=await photoData(body.photo);else if(body.remove_photo==='1')body.photo_path='images/player-placeholder.svg';delete body.photo;delete body.remove_photo;}
   if(route==='/api/session')return {user:user(),csrf:'demo',settings};
   if(route==='/api/login'){
     const admin=body.email==='admin@cantera.local'&&body.password==='Cantera2026!';
     const referee=body.email==='arbitro@cantera.local'&&body.password==='Arbitro2026!';
     if(!admin&&!referee)throw new Error('Correo o contraseña incorrectos.');
     const account={id:admin?1:2,name:admin?'Administrador':'Árbitro',email:body.email,role:admin?'admin':'referee'};
     sessionStorage.setItem(userKey,JSON.stringify(account));return {user:account,csrf:'demo'};
   }
   if(route==='/api/logout'){sessionStorage.removeItem(userKey);return {user:null,csrf:'demo'};}
   if(!user())throw new Error('Inicie sesión para continuar.');
   const s=read(),D=window.CanteraDomain;
   if(route==='/api/state')return {...clone(s),referees:[{id:2,name:'Árbitro',email:'arbitro@cantera.local'}]};
   const persist=()=>{try{localStorage.setItem(key,JSON.stringify(s));}catch{throw new Error('No se guardó el cambio: el almacenamiento de este navegador está lleno.');}};
   const auditMatch=(match,action,snapshot)=>s.tournament_match_result_audit.push({id:Math.max(0,...s.tournament_match_result_audit.map(entry=>+entry.id))+1,tournament_match_id:+match.id,actor_user_id:+user().id,action,revision:+match.result_revision||0,snapshot:clone(snapshot),created_at:new Date().toISOString()});
   const tournamentInput=()=>{
     const name=String(body.name||'').trim(),start=String(body.start_date||''),end=String(body.end_date||'');
     const periods=Number(body.extra_time_periods),minutes=Number(body.extra_time_period_minutes),attempts=Number(body.penalty_attempts_per_team),sudden=String(body.penalty_sudden_death);
     if(!name||name.length>150||!/^\d{4}-\d{2}-\d{2}$/.test(start)||!/^\d{4}-\d{2}-\d{2}$/.test(end)||start>end)throw new Error('Revise el nombre y las fechas del torneo.');
     if(!Number.isInteger(periods)||periods<1||!Number.isInteger(minutes)||minutes<1||!Number.isInteger(attempts)||attempts<1||!['0','1'].includes(sudden))throw new Error('Complete las reglas de desempate del torneo.');
     return {name,start_date:start,end_date:end,extra_time_periods:periods,extra_time_period_minutes:minutes,penalty_attempts_per_team:attempts,penalty_sudden_death:+sudden};
   };
   const tournamentPath=route.match(/^\/api\/tournaments(?:\/(\d+))?(?:\/(registration(?:\/close)?|participants(?:\/order)?|bracket|matches\/\d+\/(?:schedule|start|goals|result|confirm|correction|revision|incidents(?:\/\d+)?)))?(?:\/(\d+))?$/);
   if(tournamentPath){
     const refereeMatchOperation=user().role==='referee'&&/\/matches\/\d+\/(?:start|goals|result|confirm|revision|incidents(?:\/\d+)?)$/.test(route);
     if(user().role!=='admin'&&method!=='GET'&&!refereeMatchOperation)throw new Error('Su cuenta tiene permisos de consulta.');
     const [,idText,operation,teamText]=tournamentPath,id=+idText||0,tournament=s.tournaments.find(t=>+t.id===id);
     if(!id&&!operation){
       if(method==='GET')return {items:clone(s.tournaments)};
       const record={id:Math.max(0,...s.tournaments.map(t=>+t.id))+1,...tournamentInput(),status:'draft',draw_method:'random',registrations_closed_at:null,bracket_generated_at:null};
       s.tournaments.push(record);persist();return {record:clone(record)};
     }
     if(!tournament)throw new Error('El torneo no existe.');
     if(!operation){
       if(method==='GET')return {...clone(tournament),participants:clone(s.tournament_teams.filter(p=>+p.tournament_id===id).sort((a,b)=>+a.seed_order-+b.seed_order))};
       if(method==='POST'){
         if(tournament.status!=='draft')throw new Error('Solo se pueden editar los datos de un torneo en borrador.');
         Object.assign(tournament,tournamentInput());persist();return {record:clone(tournament)};
       }
     }
     if(operation==='registration'&&method==='POST'){if(tournament.status!=='draft')throw new Error('Solo se puede abrir la inscripción de un torneo en borrador.');tournament.status='registration';persist();return {record:clone(tournament)};}
     if(operation==='registration/close'&&method==='POST'){
       const participants=s.tournament_teams.filter(p=>+p.tournament_id===id),draw=body.draw_method;
       if(tournament.status!=='registration'||tournament.registrations_closed_at)throw new Error('Las inscripciones ya están cerradas o no están abiertas.');
       if(participants.length<2)throw new Error('Inscriba al menos dos equipos antes de cerrar las inscripciones.');
       if(!['random','manual'].includes(draw))throw new Error('Seleccione sorteo aleatorio u orden manual.');
       if(draw==='manual'&&participants.map(p=>+p.seed_order).sort((a,b)=>a-b).some((order,index)=>order!==index+1))throw new Error('Guarde primero el orden manual de todos los equipos.');
       Object.assign(tournament,{draw_method:draw,registrations_closed_at:new Date().toISOString().slice(0,19).replace('T',' ')});persist();return {record:{...clone(tournament),participants:clone(participants)}};
     }
     if(operation==='bracket'&&method==='POST'){
       const existing=s.tournament_matches.filter(m=>+m.tournament_id===id);
       if(existing.length)return {record:{...clone(tournament),matches:clone(existing)}};
       if(!tournament.registrations_closed_at)throw new Error('Cierre las inscripciones antes de generar el cuadro.');
       let ids=s.tournament_teams.filter(p=>+p.tournament_id===id).sort((a,b)=>+a.seed_order-+b.seed_order).map(p=>+p.team_id);
       if(ids.length<2)throw new Error('Se requieren al menos dos equipos para generar el cuadro.');
       if(tournament.draw_method==='random'){
         for(let i=ids.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[ids[i],ids[j]]=[ids[j],ids[i]];}
         ids.forEach((teamId,index)=>{const participant=s.tournament_teams.find(p=>+p.tournament_id===id&&+p.team_id===teamId);participant.seed_order=index+1;});
       }
       let size=1;while(size<ids.length)size*=2;
       let seeds=[1,2];
       for(let bracketSize=4;bracketSize<=size;bracketSize*=2){const next=[];for(const seed of seeds)next.push(seed,bracketSize+1-seed);seeds=next;}
       const slots=Array(size).fill(null),slotBySeed=new Map(seeds.map((seed,index)=>[seed,index]));
       ids.forEach((teamId,index)=>{slots[slotBySeed.get(index+1)]=teamId;});
       const roundMatches={},roundCount=Math.log2(size);
       let nextMatchId=Math.max(0,...s.tournament_matches.map(m=>+m.id));
       for(let pos=0;pos<size;pos+=2){
         const home=slots[pos],away=slots[pos+1];if(home===null&&away===null)continue;
         const record={id:++nextMatchId,tournament_id:id,round_number:1,match_number:pos/2+1,home_team_id:home,away_team_id:away,winner_team_id:home===null||away===null?(home??away):null,scheduled_at:null,first_kicker_team_id:null,status:home===null||away===null?'bye':'pending',workflow_status:home===null||away===null?'bye':'unprogrammed',venue:null,matchday_id:null,referee_user_id:null,result_revision:0};
         s.tournament_matches.push(record);roundMatches[1]||={};roundMatches[1][record.match_number]=record;
       }
       for(let round=2;round<=roundCount;round++){
         const count=size/(2**round);roundMatches[round]={};
         for(let number=1;number<=count;number++){
           const left=roundMatches[round-1][number*2-1],right=roundMatches[round-1][number*2];
           const record={id:++nextMatchId,tournament_id:id,round_number:round,match_number:number,home_team_id:left?.status==='bye'?left.winner_team_id:null,away_team_id:right?.status==='bye'?right.winner_team_id:null,winner_team_id:null,scheduled_at:null,first_kicker_team_id:null,status:'pending',workflow_status:'unprogrammed',venue:null,matchday_id:null,referee_user_id:null,result_revision:0};
           s.tournament_matches.push(record);roundMatches[round][number]=record;
         }
       }
       tournament.bracket_generated_at=new Date().toISOString().slice(0,19).replace('T',' ');persist();
       return {record:{...clone(tournament),matches:clone(s.tournament_matches.filter(m=>+m.tournament_id===id))}};
     }
     const matchOperation=operation?.match(/^matches\/(\d+)\/(schedule|start|goals|result|confirm|correction|revision|incidents(?:\/\d+)?)$/);
     if(matchOperation){
       const matchId=+matchOperation[1],action=matchOperation[2],match=s.tournament_matches.find(m=>+m.id===matchId&&+m.tournament_id===id);
       if(!match)throw new Error('El partido no existe en este torneo.');
       const incidentOperation=action.match(/^incidents(?:\/(\d+))?$/);
       if(incidentOperation){
         if(user().role!=='referee'||+match.referee_user_id!==+user().id)throw new Error('Solo el árbitro asignado puede modificar incidencias.');
         if(match.workflow_status!=='in_progress')throw new Error('Las incidencias solo se modifican mientras el partido está en juego.');
         const incidentId=+incidentOperation[1]||0,incidentIndex=s.incidents.findIndex(row=>+row.id===incidentId&&+row.tournament_match_id===matchId);
         if(method==='DELETE'){
           if(incidentIndex<0)throw new Error('La incidencia no existe en este partido.');
           const [removed]=s.incidents.splice(incidentIndex,1);auditMatch(match,'incident_deleted',removed);persist();return {message:'Incidencia eliminada.'};
         }
         const player=s.players.find(row=>+row.id===+body.player_id),minute=+body.minute,cardType=String(body.card_type||''),description=String(body.description||'').trim(),incidentDate=String(match.scheduled_at||'').slice(0,10),suspensionDate=cardType==='red'?String(body.suspension_date||''):null;
         if(!player||![+match.home_team_id,+match.away_team_id].includes(+player.team_id)||!Number.isInteger(minute)||minute<0||minute>150||!['yellow','red'].includes(cardType)||!description||description.length>500||cardType==='red'&&(!/^\d{4}-\d{2}-\d{2}$/.test(suspensionDate)||suspensionDate<incidentDate))throw new Error('Revise el jugador, minuto, tarjeta, descripción y suspensión.');
         const previous=incidentIndex>=0?clone(s.incidents[incidentIndex]):null,record={id:incidentId||Math.max(0,...s.incidents.map(row=>+row.id))+1,player_id:+player.id,team_id:+player.team_id,tournament_match_id:matchId,minute,card_type:cardType,description,incident_date:incidentDate,suspension_date:suspensionDate};
         if(incidentIndex>=0)s.incidents[incidentIndex]=record;else s.incidents.push(record);
         auditMatch(match,incidentId?'incident_updated':'incident_created',{before:previous,after:record});persist();return {record:clone(record)};
       }
       if(action==='schedule'){
         if(!['unprogrammed','scheduled'].includes(match.workflow_status)||match.status!=='pending'||!match.home_team_id||!match.away_team_id)throw new Error('Solo se pueden programar partidos pendientes con ambos equipos definidos.');
         const value=String(body.scheduled_at||''),venue=String(body.venue||'').trim(),refereeId=+body.referee_user_id,matchdayId=body.matchday_id?+body.matchday_id:null;
         if(!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)||!venue||venue.length>150||refereeId!==2||matchdayId&&!s.matchdays.some(day=>+day.id===matchdayId))throw new Error('Complete la fecha, cancha y un árbitro válido.');
         const normalized=value.replace('T',' ')+':00';
         for(const other of s.tournament_matches.filter(other=>+other.id!==matchId&&+other.tournament_id===id&&other.scheduled_at===normalized)){
           if([+other.home_team_id,+other.away_team_id].some(teamId=>[+match.home_team_id,+match.away_team_id].includes(teamId)))throw new Error('Un equipo no puede jugar dos partidos a la misma hora.');
           if(other.venue?.trim().toLowerCase()===venue.toLowerCase())throw new Error('La cancha ya está ocupada a esa hora.');
           if(+other.referee_user_id===refereeId)throw new Error('El árbitro ya tiene un partido a la misma hora.');
         }
         match.scheduled_at=normalized;match.venue=venue;match.referee_user_id=refereeId;match.matchday_id=matchdayId;match.workflow_status='scheduled';auditMatch(match,'scheduled',{scheduled_at:normalized,venue,referee_user_id:refereeId,matchday_id:matchdayId});persist();return {record:clone(match)};
       }
       if(action==='start'){
         if(match.workflow_status!=='scheduled'||!match.scheduled_at||!match.referee_user_id)throw new Error('Solo se puede iniciar un partido programado y con árbitro asignado.');
         if(user().role==='referee'&&+match.referee_user_id!==+user().id)throw new Error('Este partido no está asignado a su cuenta de árbitro.');
         match.workflow_status='in_progress';match.started_at=new Date().toISOString();auditMatch(match,'started',{workflow_status:'in_progress'});persist();return {record:clone(match)};
       }
       if(action==='goals'){
         if(method!=='POST'||match.workflow_status!=='in_progress')throw new Error('Solo se pueden registrar goles durante un partido en juego.');
         if(user().role==='referee'&&+match.referee_user_id!==+user().id)throw new Error('Este partido no está asignado a su cuenta de árbitro.');
         const player=s.players.find(row=>+row.id===+body.player_id),teamId=+body.team_id,minute=+body.minute;
         if(!player||+player.team_id!==teamId||![+match.home_team_id,+match.away_team_id].includes(teamId)||!Number.isInteger(minute)||minute<0||minute>150)throw new Error('Seleccione un jugador del equipo participante y un minuto válido.');
         const record={id:Math.max(0,...s.tournament_match_goal_events.map(row=>+row.id))+1,tournament_match_id:matchId,player_id:+player.id,team_id:teamId,minute,own_goal:0,created_by_user_id:+user().id,created_at:new Date().toISOString()};
         s.tournament_match_goal_events.push(record);auditMatch(match,'goal_recorded',record);persist();return {record:clone(record)};
       }
       if(action==='confirm'){
         if(match.workflow_status!=='awaiting_confirmation'||!match.winner_team_id)throw new Error('El resultado no está pendiente de confirmación.');
         match.workflow_status='confirmed';match.confirmed_at=new Date().toISOString();match.confirmed_by_user_id=+user().id;auditMatch(match,'confirmed',{winner_team_id:+match.winner_team_id});
         const parent=s.tournament_matches.find(other=>+other.tournament_id===id&&+other.round_number===+match.round_number+1&&+other.match_number===Math.ceil(+match.match_number/2));
         if(parent){const slot=+match.match_number%2?'home_team_id':'away_team_id';if(parent[slot]!=null&&+parent[slot]!==+match.winner_team_id)throw new Error('El partido siguiente ya contiene otro clasificado.');parent[slot]=+match.winner_team_id;tournament.status='in_progress';}else tournament.status='finished';
         persist();return {record:{...clone(tournament),matches:clone(s.tournament_matches.filter(other=>+other.tournament_id===id))}};
       }
       if(action==='correction'){
         if(user().role!=='admin'||match.workflow_status!=='confirmed')throw new Error('Solo el administrador puede corregir un resultado confirmado.');
         const parent=s.tournament_matches.find(other=>+other.tournament_id===id&&+other.round_number===+match.round_number+1&&+other.match_number===Math.ceil(+match.match_number/2));
         if(parent&&(parent.status!=='pending'||!['unprogrammed','scheduled'].includes(parent.workflow_status)))throw new Error('No se puede corregir: el partido siguiente ya comenzó o fue confirmado.');
         const snapshot={match:clone(match)};
         for(const key of ['tournament_match_period_scores','tournament_match_goals','tournament_match_goal_events','tournament_match_penalties']){snapshot[key]=clone(s[key].filter(entry=>+entry.tournament_match_id===matchId));s[key]=s[key].filter(entry=>+entry.tournament_match_id!==matchId);}
         if(parent){const slot=+match.match_number%2?'home_team_id':'away_team_id';if(+parent[slot]===+match.winner_team_id)parent[slot]=null;}
         match.winner_team_id=null;match.first_kicker_team_id=null;match.status='pending';match.workflow_status='in_progress';match.confirmed_at=null;match.confirmed_by_user_id=null;match.result_revision=(+match.result_revision||0)+1;tournament.status='in_progress';
         auditMatch(match,'exceptional_correction',snapshot);
       }
       if(action==='revision'){
         if(match.workflow_status!=='awaiting_confirmation'||match.status!=='completed')throw new Error('Solo se puede editar un resultado pendiente de confirmación.');
         const snapshot={match:clone(match)};
         for(const key of ['tournament_match_period_scores','tournament_match_goals','tournament_match_goal_events','tournament_match_penalties']){snapshot[key]=clone(s[key].filter(entry=>+entry.tournament_match_id===matchId));s[key]=s[key].filter(entry=>+entry.tournament_match_id!==matchId);}
         match.winner_team_id=null;match.first_kicker_team_id=null;match.status='pending';match.workflow_status='in_progress';auditMatch(match,'result_revised',snapshot);
       }
       if(match.workflow_status!=='in_progress'||match.status!=='pending'||!match.home_team_id||!match.away_team_id)throw new Error('El partido debe estar en juego para registrar un resultado.');
       if(!match.scheduled_at)throw new Error('Programe el partido antes de registrar el resultado.');
       if(!tournament.extra_time_periods||!tournament.extra_time_period_minutes||!tournament.penalty_attempts_per_team||tournament.penalty_sudden_death==null)throw new Error('Configure las reglas de desempate antes de registrar resultados.');
       const periods=body.period_scores;
       if(!Array.isArray(periods)||!periods.length)throw new Error('Ingrese el marcador reglamentario.');
       let homeScore=0,awayScore=0,homeOwn=0,awayOwn=0;
       periods.forEach((period,index)=>{if(+period.period_number!==index||![period.home_score,period.away_score].every(v=>Number.isInteger(+v)&&+v>=0)||![period.home_own_goals||0,period.away_own_goals||0].every(v=>Number.isInteger(+v)&&+v>=0))throw new Error('Marcador de período inválido.');homeScore+=+period.home_score;awayScore+=+period.away_score;homeOwn+=+(period.home_own_goals||0);awayOwn+=+(period.away_own_goals||0);});
       const tiedAfterRegulation=+periods[0].home_score===+periods[0].away_score;
       if(!tiedAfterRegulation&&periods.length!==1||tiedAfterRegulation&&periods.length!==+tournament.extra_time_periods+1)throw new Error('Registre los períodos de tiempo extra requeridos.');
       const goalsByTeam={[+match.home_team_id]:0,[+match.away_team_id]:0},playerGoals=[],seenPlayerIds=new Set();
       for(const entry of body.player_goals||[]){const player=s.players.find(p=>+p.id===+entry.player_id),goals=+entry.goals;if(!player||!Number.isInteger(goals)||goals<0)throw new Error('Goleador inválido.');if(seenPlayerIds.has(+player.id))throw new Error('Cada jugador debe aparecer una sola vez en los goles del partido.');seenPlayerIds.add(+player.id);if(!goals)continue;const teamId=+player.team_id;if(!(teamId in goalsByTeam))throw new Error('El goleador debe pertenecer a un equipo del partido.');if(D.suspended(s,player.id,match.scheduled_at.slice(0,10),matchId))throw new Error('Un jugador suspendido no puede figurar como goleador.');goalsByTeam[teamId]+=goals;playerGoals.push({player_id:+player.id,team_id:teamId,goals});}
       if(goalsByTeam[+match.home_team_id]+awayOwn!==homeScore||goalsByTeam[+match.away_team_id]+homeOwn!==awayScore)throw new Error('El marcador no coincide con goles individuales y autogoles.');
       const goalEvents=body.goal_events||[],eventScorers={},eventOwn={[+match.home_team_id]:0,[+match.away_team_id]:0};
       if(!Array.isArray(goalEvents))throw new Error('Lista de goles inválida.');
       for(const event of goalEvents){const player=s.players.find(p=>+p.id===+event.player_id),minute=+event.minute,ownGoal=+event.own_goal;if(!player||!Number.isInteger(minute)||minute<0||minute>150||![0,1].includes(ownGoal)||![+match.home_team_id,+match.away_team_id].includes(+player.team_id))throw new Error('Gol inválido: revise jugador, equipo y minuto.');if(D.suspended(s,player.id,match.scheduled_at.slice(0,10),matchId))throw new Error('Un jugador suspendido no puede figurar como autor de un gol.');if(ownGoal)eventOwn[+player.team_id]++;else eventScorers[+player.id]=(eventScorers[+player.id]||0)+1;event.player_id=+player.id;event.team_id=+player.team_id;event.minute=minute;event.own_goal=ownGoal;}
       for(const incident of s.incidents.filter(row=>+row.tournament_match_id===matchId&&row.card_type==='red'))if(goalEvents.some(event=>+event.player_id===+incident.player_id&&+event.minute>=+incident.minute))throw new Error('Un jugador expulsado no puede anotar después de la tarjeta roja.');
       const expectedScorers=Object.fromEntries(playerGoals.map(goal=>[goal.player_id,goal.goals]));
       if(JSON.stringify(Object.entries(eventScorers).sort())!==JSON.stringify(Object.entries(expectedScorers).sort())||eventOwn[+match.home_team_id]!==homeOwn||eventOwn[+match.away_team_id]!==awayOwn)throw new Error('Cada gol del marcador debe tener jugador y minuto, incluidos los autogoles.');
       let winner=homeScore===awayScore?0:homeScore>awayScore?+match.home_team_id:+match.away_team_id,firstKicker=null,penalties=[];
       if(!winner){
         firstKicker=+body.first_kicker_team_id;if(![+match.home_team_id,+match.away_team_id].includes(firstKicker)||!Array.isArray(body.penalty_kicks))throw new Error('Seleccione el primer pateador e ingrese la tanda.');
         const attempts={[+match.home_team_id]:0,[+match.away_team_id]:0},scores={[+match.home_team_id]:0,[+match.away_team_id]:0},initial=+tournament.penalty_attempts_per_team;
         let sudden=false;
         for(let index=0;index<body.penalty_kicks.length;index++){
           const kick=body.penalty_kicks[index],expected=index%2===0?firstKicker:firstKicker===+match.home_team_id?+match.away_team_id:+match.home_team_id,teamId=+kick.team_id,scored=+kick.scored;
           if(teamId!==expected||![0,1].includes(scored))throw new Error('Revise el orden y resultado de los tiros.');
           attempts[teamId]++;scores[teamId]+=scored;penalties.push({team_id:teamId,scored});
           if(!sudden){
             if(scores[+match.home_team_id]>scores[+match.away_team_id]+Math.max(0,initial-attempts[+match.away_team_id]))winner=+match.home_team_id;
             else if(scores[+match.away_team_id]>scores[+match.home_team_id]+Math.max(0,initial-attempts[+match.home_team_id]))winner=+match.away_team_id;
             else if(attempts[+match.home_team_id]>=initial&&attempts[+match.away_team_id]>=initial){if(scores[+match.home_team_id]!==scores[+match.away_team_id])winner=scores[+match.home_team_id]>scores[+match.away_team_id]?+match.home_team_id:+match.away_team_id;else if(!+tournament.penalty_sudden_death)throw new Error('La tanda terminó empatada y este torneo no aplica muerte súbita.');else sudden=true;}
           }else if(attempts[+match.home_team_id]===attempts[+match.away_team_id]&&scores[+match.home_team_id]!==scores[+match.away_team_id])winner=scores[+match.home_team_id]>scores[+match.away_team_id]?+match.home_team_id:+match.away_team_id;
           if(winner&&index<body.penalty_kicks.length-1)throw new Error('La tanda continuó después de que ya había un ganador.');
         }
         if(!winner)throw new Error('La tanda debe continuar hasta que exista un ganador.');
       }else if(body.penalty_kicks?.length)throw new Error('No registre penales si el partido ya tuvo un ganador en el juego.');
       let nextScoreId=Math.max(0,...s.tournament_match_period_scores.map(p=>+p.id)),nextGoalId=Math.max(0,...s.tournament_match_goals.map(g=>+g.id)),nextPenaltyId=Math.max(0,...s.tournament_match_penalties.map(p=>+p.id)),nextEventId=Math.max(0,...s.tournament_match_goal_events.map(e=>+e.id));
       periods.forEach(period=>s.tournament_match_period_scores.push({id:++nextScoreId,tournament_match_id:matchId,...period}));
       playerGoals.forEach(goal=>s.tournament_match_goals.push({id:++nextGoalId,tournament_match_id:matchId,...goal}));
       goalEvents.forEach(event=>s.tournament_match_goal_events.push({id:++nextEventId,tournament_match_id:matchId,created_by_user_id:+user().id,...event}));
       penalties.forEach((kick,index)=>s.tournament_match_penalties.push({id:nextPenaltyId+index+1,tournament_match_id:matchId,kick_number:index+1,...kick}));
       match.winner_team_id=winner;match.first_kicker_team_id=firstKicker;match.status='completed';match.workflow_status='awaiting_confirmation';match.result_revision=(+match.result_revision||0)+1;
       auditMatch(match,'result_submitted',{winner_team_id:winner,period_scores:periods,player_goals:playerGoals,goal_events:goalEvents,penalty_kicks:penalties});
       tournament.status='in_progress';
       persist();return {record:{...clone(tournament),matches:clone(s.tournament_matches.filter(m=>+m.tournament_id===id))}};
     }
     if(operation==='participants'&&method==='POST'){
       if(tournament.status!=='registration'||tournament.registrations_closed_at)throw new Error('Las inscripciones no están abiertas.');
       const teamId=+body.team_id,club=s.teams.find(t=>+t.id===teamId);
       if(!club)throw new Error('El equipo no existe.');
       if(!+club.active)throw new Error('No se pueden inscribir equipos inactivos.');
       const participants=s.tournament_teams.filter(p=>+p.tournament_id===id);
       if(participants.some(p=>+p.team_id===teamId))throw new Error('El equipo ya está inscrito en este torneo.');
       const record={id:Math.max(0,...s.tournament_teams.map(p=>+p.id))+1,tournament_id:id,team_id:teamId,seed_order:Math.max(0,...participants.map(p=>+p.seed_order))+1};
       s.tournament_teams.push(record);persist();return {record:clone(record)};
     }
     if(operation==='participants/order'&&method==='POST'){
       if(tournament.status!=='registration'||tournament.registrations_closed_at)throw new Error('El orden no se puede cambiar después de cerrar la inscripción.');
       const ids=body.team_ids,participants=s.tournament_teams.filter(p=>+p.tournament_id===id);
       if(!Array.isArray(ids)||ids.length!==participants.length||new Set(ids.map(Number)).size!==ids.length||participants.some(p=>!ids.map(Number).includes(+p.team_id)))throw new Error('El orden debe contener exactamente los equipos inscritos.');
       ids.forEach((teamId,index)=>{const row=participants.find(p=>+p.team_id===+teamId);row.seed_order=index+1;});persist();return {...clone(tournament),participants:clone(participants)};
     }
     if(operation==='participants'&&teamText&&method==='DELETE'){
       if(tournament.status!=='registration'||tournament.registrations_closed_at)throw new Error('No se pueden cambiar los participantes después de cerrar la inscripción.');
       const participant=s.tournament_teams.find(p=>+p.tournament_id===id&&+p.team_id===+teamText);
       if(!participant)throw new Error('El equipo no está inscrito en este torneo.');
       s.tournament_teams=s.tournament_teams.filter(p=>p!==participant);
       s.tournament_teams.filter(p=>+p.tournament_id===id).sort((a,b)=>+a.seed_order-+b.seed_order).forEach((p,index)=>p.seed_order=index+1);
       persist();return {message:'Inscripción retirada.'};
     }
   }
   if(route.startsWith('/api/reports/'))return {rows:D.report(s,route.split('/').at(-1),Object.fromEntries(url.searchParams),settings)};
   if(route.startsWith('/api/player/'))return D.player(s,+route.split('/').at(-1),settings);
   const [, ,entity,idText]=route.split('/'),id=+idText||0;
   if(!['teams','players','matchdays','goals','incidents'].includes(entity))throw new Error('Ruta no encontrada.');
   if(method==='GET')return {items:s[entity],total:s[entity].length};
   if(user().role!=='admin')throw new Error('Su cuenta tiene permisos de consulta.');
   if(id&&!s[entity].some(r=>+r.id===id))throw new Error('El registro no existe.');
   if(method==='DELETE'){
     if(!D.canDelete(s,entity,id))throw new Error('Este registro tiene información asociada. Se protegió su historial.');
     s[entity]=s[entity].filter(r=>+r.id!==id);
   }else{
     for(const [k,v] of Object.entries(body))if(typeof v==='string')body[k]=v.trim();
     const record=D.validate(s,entity,body,id,settings);
     record.id=id||Math.max(0,...s[entity].map(r=>+r.id))+1;
     if(id)s[entity]=s[entity].map(r=>+r.id===id?{...r,...record}:r);else s[entity].push(record);
   }
   try{localStorage.setItem(key,JSON.stringify(s));}catch{throw new Error('No se guardó el cambio: el almacenamiento de este navegador está lleno.');}
   return {message:method==='DELETE'?'Registro eliminado.':'Registro guardado.'};
 };
})();
